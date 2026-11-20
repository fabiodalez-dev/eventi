@php
    use App\Enums\OccurrenceStatus;
    use App\Filament\Support\EventStatusPresentation;
    use App\Queries\EventOccurrenceQuery;
    use App\Support\Poster;

    /*
     * La scheda di un evento in sola lettura, nei pannelli dei locali e degli
     * organizzatori: card impilate a tutta larghezza (testata, informazioni,
     * descrizione, serate). Ogni colore ha la sua variante scura.
     */
    $event = $getRecord();
    $event->loadMissing(['city', 'category', 'venue', 'organizer', 'tags', 'media']);
    $timezone = $event->city->timezone;
    // La relazione si interroga per intero: l'elenco precarica solo la prossima data.
    $dates = $event->occurrences()->with(['venue', 'lineups'])->orderBy('starts_at')->get()->each(fn ($date) => $date->setRelation('event', $event));
    $today = EventOccurrenceQuery::for($event->city)->currentBusinessDate();
    [$upcoming, $past] = $dates->partition(fn ($date) => $date->business_date->format('Y-m-d') >= $today);
    $past = $past->reverse()->values();
    $next = $upcoming->first();
    $last = $past->first();
    $posterUrl = Poster::url($event);
    $editUrl ??= null;
    $statisticsUrl ??= null;
    /*
     * Le pagine collegate all'evento, già filtrate su di lui: statistiche,
     * grafiche social, sponsorizzazione, biglietteria della prossima data,
     * scheda pubblica. Solo quelle che chi guarda può aprire.
     */
    $links = collect(\App\Filament\Venue\Resources\Events\EventResource::eventLinks($event, withEdit: false))
        ->map(fn (array $link): array => $link['key'] === 'statistics' && $statisticsUrl ? [...$link, 'url' => $statisticsUrl] : $link)
        ->all();
    $when = fn ($date) => \App\Filament\Support\PanelDate::event($date->starts_at, $timezone);
    $price = match (true) {
        $event->price_min !== null && $event->price_max !== null && (float) $event->price_max !== (float) $event->price_min => __('manage.event_view.price_from_to', ['min' => number_format((float) $event->price_min, 2, ',', '.'), 'max' => number_format((float) $event->price_max, 2, ',', '.')]),
        $event->price_min !== null => __('manage.event_view.price_from', ['min' => number_format((float) $event->price_min, 2, ',', '.')]),
        $event->price_max !== null => __('manage.event_view.price_up_to', ['max' => number_format((float) $event->price_max, 2, ',', '.')]),
        default => null,
    };
    $facts = array_filter([
        __('manage.fields.status') => $event->status->label(),
        // Il motivo arriva anche per email, ma chi corregge la scheda lo cerca qui.
        __('manage.fields.rejection_reason') => $event->status === \App\Enums\EventStatus::Rejected ? $event->rejection_reason : null,
        __('manage.fields.category') => $event->category?->name,
        __('analytics-dashboard.venue') => $event->venue?->name,
        __('analytics-dashboard.organizer') => $event->organizer?->name ?? $event->organizer_name ?? __('analytics-dashboard.no_organizer'),
        __('manage.event_view.price_range') => trim($event->price_type->label().($price !== null ? ' · '.$price : '')),
        __('manage.event_view.tags') => $event->tags->pluck('name')->join(', '),
    ], fn ($value) => filled($value));
@endphp
<div class="flex min-w-0 flex-col gap-6" data-event-read-view>
    {{-- Testata: locandina, stato, data che conta e pulsanti. --}}
    <x-filament::section data-event-head>
        <div class="flex min-w-0 items-start gap-4 sm:gap-6">
            @if ($posterUrl)
                <img src="{{ $posterUrl }}" alt="{{ __('manage.event_view.poster_alt', ['title' => $event->title]) }}" width="128" height="171" loading="lazy" decoding="async"
                    class="aspect-[3/4] w-24 shrink-0 rounded-lg bg-gray-100 object-cover ring-1 ring-gray-950/5 sm:w-32 dark:bg-white/5 dark:ring-white/10" data-event-poster>
            @else
                <div class="flex aspect-[3/4] w-24 shrink-0 flex-col items-center justify-center gap-2 rounded-lg bg-gray-100 p-2 text-center text-xs text-gray-500 ring-1 ring-gray-950/5 sm:w-32 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10" data-poster-placeholder>
                    <x-filament::icon icon="heroicon-o-photo" class="size-8" />
                    <span>{{ __('manage.event_view.no_poster') }}</span>
                </div>
            @endif

            <div class="flex min-w-0 flex-1 flex-col gap-3">
                <div class="flex flex-wrap gap-2">
                    <x-filament::badge :color="EventStatusPresentation::color($event->status)">{{ $event->status->label() }}</x-filament::badge>
                    @if (! $next && $last)
                        <x-filament::badge color="gray">{{ __('manage.event_list.past') }}</x-filament::badge>
                    @endif
                    @if ($event->source->isImported())
                        <x-filament::badge color="gray" icon="heroicon-o-arrow-down-tray">{{ $event->source->label() }}</x-filament::badge>
                    @endif
                </div>
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $next ? __('manage.event_view.next_date') : ($last ? __('manage.event_view.last_date') : __('manage.event_view.no_dates')) }}</p>
                    @if ($next || $last)
                        <p class="text-lg font-semibold text-gray-950 sm:text-xl dark:text-white">{{ $when($next ?? $last) }}</p>
                    @endif
                </div>
                @if (($next ?? $last)?->locationLabel() ?? $event->venue?->name)
                    <p class="flex min-w-0 items-start gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                        <x-filament::icon icon="heroicon-o-map-pin" class="mt-0.5 size-4 shrink-0" />
                        <span class="min-w-0 [overflow-wrap:anywhere]">{{ ($next ?? $last)?->locationLabel() ?? $event->venue?->name }}</span>
                    </p>
                @endif
            </div>
        </div>

        @if ($editUrl || $links !== [])
            <div class="mt-6 flex flex-wrap gap-3" data-event-actions>
                @if ($editUrl)
                    <x-filament::button tag="a" :href="$editUrl" icon="heroicon-o-pencil-square" data-event-link="edit">{{ __('manage.actions.edit') }}</x-filament::button>
                @endif
                @foreach ($links as $link)
                    <x-filament::button tag="a" :href="$link['url']" color="gray" outlined :icon="$link['icon']"
                        :target="$link['external'] ? '_blank' : null" :rel="$link['external'] ? 'noopener' : null" :data-event-link="$link['key']">{{ $link['label'] }}</x-filament::button>
                @endforeach
            </div>
        @endif
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('manage.event_view.hint') }}</p>
    </x-filament::section>

    {{-- Informazioni: un'etichetta e il suo valore per riga. --}}
    <x-filament::section :heading="__('manage.event_view.info')" :description="__('manage.event_view.info_description')">
        <dl class="divide-y divide-gray-100 dark:divide-white/10" data-event-facts>
            @foreach ($facts as $label => $value)
                <div class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0 sm:flex-row sm:gap-6">
                    <dt class="shrink-0 text-sm text-gray-500 sm:w-44 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="min-w-0 text-sm font-medium text-gray-950 [overflow-wrap:anywhere] dark:text-white">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>

    {{-- Descrizione, con gli a capo di chi l'ha scritta. --}}
    <x-filament::section :heading="__('manage.event_view.description')">
        @if (filled(trim(strip_tags((string) $event->description))))
            <div class="text-sm leading-relaxed text-gray-950 dark:text-gray-100 [&_a]:text-primary-600 dark:[&_a]:text-primary-400" data-event-description>
                <x-description-content :text="$event->description" />
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('manage.event_view.no_description') }}</p>
        @endif
    </x-filament::section>

    {{-- Serate: una card per data, prima quelle in programma. --}}
    <x-filament::section :heading="__('manage.event_view.dates')" :description="trans_choice('manage.event_view.dates_description', $dates->count())" data-event-occurrences-read>
        <div class="flex flex-col gap-6">
            @foreach (['upcoming_dates' => $upcoming, 'past_dates' => $past] as $group => $groupDates)
                @continue($groupDates->isEmpty())
                <div class="flex flex-col gap-3">
                    @if ($upcoming->isNotEmpty() && $past->isNotEmpty())
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('manage.event_view.'.$group) }}</h3>
                    @endif
                    <ul class="flex flex-col gap-3">
                        @foreach ($groupDates as $date)
                            @php
                                $start = $date->starts_at->timezone($timezone);
                                $end = $date->ends_at?->timezone($timezone);
                                $details = array_filter([
                                    ['heroicon-o-map-pin', $date->locationLabel()],
                                    $date->doors_at ? ['heroicon-o-key', __('manage.event_view.doors', ['time' => $date->doors_at->timezone($timezone)->format('H:i')])] : null,
                                    $date->lineups->isNotEmpty() ? ['heroicon-o-musical-note', __('manage.event_view.lineup', ['names' => $date->lineups->pluck('name')->join(', ')])] : null,
                                    $date->capacity !== null ? ['heroicon-o-user-group', $date->capacity_left !== null ? __('manage.event_view.capacity', ['left' => $date->capacity_left, 'total' => $date->capacity]) : __('manage.event_view.capacity_total', ['total' => $date->capacity])] : null,
                                    filled($date->status_note) ? ['heroicon-o-information-circle', $date->status_note] : null,
                                ], fn ($row) => $row !== null && filled($row[1]));
                            @endphp
                            <li class="rounded-lg border border-gray-200 p-4 dark:border-white/10 {{ $group === 'past_dates' ? 'bg-gray-50 dark:bg-white/5' : 'bg-white dark:bg-gray-900' }}" data-occurrence-read="{{ $date->id }}">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-semibold text-gray-950 dark:text-white">{{ $when($date) }}</p>
                                        @if ($end)
                                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $end->isSameDay($start) ? __('manage.event_view.ends', ['time' => $end->format('H:i')]) : __('manage.event_view.ends_other_day', ['date' => \App\Filament\Support\PanelDate::event($end, $timezone)]) }}</p>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @if ($group === 'past_dates' && $date->status === OccurrenceStatus::Scheduled)
                                            <x-filament::badge color="gray">{{ __('manage.event_view.past_date') }}</x-filament::badge>
                                        @else
                                            <x-filament::badge :color="EventStatusPresentation::occurrenceColor($date->status)">{{ $date->status->label() }}</x-filament::badge>
                                        @endif
                                        @if (filled($date->highlight))
                                            <x-filament::badge color="primary">{{ $date->highlight }}</x-filament::badge>
                                        @endif
                                    </div>
                                </div>
                                @if ($details !== [])
                                    <ul class="mt-3 flex flex-col gap-1.5">
                                        @foreach ($details as [$icon, $text])
                                            <li class="flex min-w-0 items-start gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                                                <x-filament::icon :icon="$icon" class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-500" />
                                                <span class="min-w-0 [overflow-wrap:anywhere]">{{ $text }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
            @if ($dates->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics-dashboard.no_occurrences') }}</p>
            @endif
        </div>
    </x-filament::section>
</div>
