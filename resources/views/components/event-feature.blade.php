{{--
    Un'occorrenza sola, in orizzontale.

    Nasce da un difetto visibile: una griglia da quattro colonne con dentro un
    evento solo produce una card sperduta a sinistra e tre colonne di niente.
    Capita di continuo proprio dove fa più danno — "In corso adesso" e "Inizia
    tra poco" hanno spesso una voce o due, e sono le due sezioni che rispondono
    alla domanda per cui il sito esiste.

    Qui la stessa occorrenza occupa la riga per intero e guadagna la scala che
    merita: la locandina resta 3:4 ma incolonnata a sinistra, e accanto c'è lo
    spazio per dire quando, dove, quanto costa e cos'è.

    Il contesto temporale arriva da chi disegna la sezione, mai dedotto qui:
    vale la stessa ragione scritta in `event-card`.
--}}
@props([
    'occurrence',
    'context' => 'upcoming',
    'showVenue' => true,
    'eager' => false,
    'level' => 'h3',
])

@php
    $formatter = app(\App\Support\DateFormatter::class);

    $event = $occurrence->event;
    $venue = $occurrence->effectiveVenue();
    $category = $event->category;
    $status = $occurrence->status;

    $url = \Illuminate\Support\Facades\Route::has('events.show')
        ? route('events.show', $event)
        : null;

    $poster = \App\Support\Poster::imageSet($event);

    $isScheduled = $status === \App\Enums\OccurrenceStatus::Scheduled;

    $stateBadge = match (true) {
        $status === \App\Enums\OccurrenceStatus::Cancelled => ['tone' => 'alert', 'label' => __('events.badge.cancelled'), 'dot' => false],
        $status === \App\Enums\OccurrenceStatus::SoldOut => ['tone' => 'muted', 'label' => __('events.badge.sold_out'), 'dot' => false],
        $status === \App\Enums\OccurrenceStatus::Postponed => ['tone' => 'muted', 'label' => __('events.badge.postponed'), 'dot' => false],
        $status === \App\Enums\OccurrenceStatus::Moved => ['tone' => 'muted', 'label' => __('events.badge.moved'), 'dot' => false],
        $context === 'ongoing' => ['tone' => 'live', 'label' => __('events.badge.ongoing'), 'dot' => 'pulse'],
        $context === 'starting_soon' => ['tone' => 'soon', 'label' => __('events.badge.starting_soon', ['countdown' => $formatter->countdown($occurrence->starts_at)]), 'dot' => false],
        $context === 'tonight' => ['tone' => 'brand', 'label' => __('events.badge.tonight', ['time' => $formatter->time($occurrence->starts_at)]), 'dot' => false],
        $context === 'today' => ['tone' => 'brand', 'label' => __('events.badge.today', ['time' => $formatter->time($occurrence->starts_at)]), 'dot' => false],
        $context === 'tomorrow' => ['tone' => 'neutral', 'label' => __('events.badge.tomorrow', ['time' => $formatter->time($occurrence->starts_at)]), 'dot' => false],
        default => null,
    };

    $whenLabel = $occurrence->is_all_day
        ? $formatter->day($occurrence->business_date).' '.__('common.separator').' '.__('events.badge.all_day')
        : $formatter->dayAndTime($occurrence->business_date, $occurrence->starts_at);

    $place = match (true) {
        $venue !== null => $venue->name,
        is_array($event->custom_location) && filled($event->custom_location['name'] ?? null) => $event->custom_location['name'],
        default => null,
    };

    $area = $venue?->municipality;

    /* Il sommario esiste per la scheda: qui aggiunge la riga che in una card
       stretta non ci starebbe, e che a schermo largo è ciò che distingue un
       annuncio da una miniatura. */
    $lead = $event->short_description;
@endphp

<article {{ $attributes->class([
    'group relative grid grid-cols-[minmax(0,6.5rem)_minmax(0,1fr)] gap-4 overflow-hidden bg-canvas border-2 border-line transition duration-300 ease-out-soft sm:grid-cols-[minmax(0,10rem)_minmax(0,1fr)] sm:gap-6 lg:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]',
    ' hover:border-accent' => $url !== null,
    'opacity-75' => ! $isScheduled,
]) }}>
    <div class="relative aspect-[3/4] w-full overflow-hidden poster-placeholder sm:aspect-auto sm:h-full">
        @if ($poster !== null)
            <x-media-image
                :set="$poster"
                :alt="__('events.card.poster_alt', ['title' => $event->title])"
                width="800"
                height="1067"
                sizes="(min-width: 1024px) 192px, (min-width: 640px) 160px, 104px"
                :eager="$eager"
                class="size-full object-cover transition duration-500 ease-out-soft group-hover:scale-[1.03]"
            />
        @else
            <span class="absolute inset-0 flex items-center justify-center p-4 text-center text-eyebrow text-on-brand-soft/70">
                {{ $event->title }}
            </span>
        @endif
    </div>

    <div class="flex flex-col justify-center gap-2 py-3 pr-4 sm:gap-2.5 sm:py-5 sm:pl-0 sm:pr-6">
        {{-- I badge stanno accanto al testo, non sopra la locandina: qui c'è
             larghezza, e sovrapporli all'immagine coprirebbe proprio la parte
             che a questa scala si vede bene. --}}
        <div class="flex flex-wrap items-center gap-2">
            @if ($stateBadge !== null)
                <x-badge :tone="$stateBadge['tone']" :dot="$stateBadge['dot']">
                    {{ $stateBadge['label'] }}
                </x-badge>
            @endif

            @if ($category !== null)
                <x-badge tone="neutral" size="sm" :dot="true" :dot-color="$category->color">
                    {{ $category->name }}
                </x-badge>
            @endif
        </div>

        <{{ $level }} class="text-card text-ink sm:text-feature">
            @if ($url !== null)
                <a href="{{ $url }}" class="after:absolute after:inset-0 after:content-['']">{{ $event->title }}</a>
            @else
                {{ $event->title }}
            @endif
        </{{ $level }}>

        <p class="text-base text-ink-muted">
            <time datetime="{{ $occurrence->is_all_day ? $formatter->isoDay($occurrence->business_date) : $formatter->iso($occurrence->starts_at) }}">
                {{ $whenLabel }}
            </time>

            @if ($showVenue && ($place !== null || $area !== null))
                <span aria-hidden="true" class="text-ink-subtle">{{ __('common.separator') }}</span>
                <span class="text-ink-subtle">{{ $place ?? __('events.card.no_venue') }}@if ($area), {{ $area }}@endif</span>
            @endif
        </p>

        @if (filled($lead))
            <p class="max-w-prose text-sm text-ink-subtle line-clamp-2">{{ $lead }}</p>
        @endif

        <div class="flex flex-wrap items-center gap-2 pt-1">
            <x-price-tag :event="$event" />

            @if ($event->is_outdoor)
                <x-badge tone="neutral" size="sm">{{ __('events.badge.outdoor') }}</x-badge>
            @endif

            @if ($isScheduled)
                <x-save-heart
                    class="ml-auto"
                    :occurrence="$occurrence"
                    :saved="app(\App\Support\CurrentSaves::class)->has((int) $occurrence->getKey())"
                />
            @endif
        </div>
    </div>
</article>
