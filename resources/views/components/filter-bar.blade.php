{{--
    Il pannello dei filtri della lista eventi (§11.3).

    Due strumenti per la stessa cosa, e nessuno dei due richiede JavaScript:

    - le **pillole** sono link, uno per ogni filtro rapido. Toccarne una
      cambia l'indirizzo, che è l'unico stato della pagina;
    - il **modulo** in fondo è un `form` con metodo GET verso `/eventi`: i
      filtri che non stanno in una pillola (comune, locale, intervallo di
      date, ordinamento) si scelgono lì e finiscono nella stessa query string.

    Il modulo si porta dietro i filtri accesi dalle pillole come campi nascosti,
    altrimenti premere "Mostra i risultati" li spegnerebbe silenziosamente.
--}}
@props([
    'filters',
    'counts' => null,
    'categories',
    'tags',
    'municipalities',
    'zones' => [],
    'venues',
    'total' => null,
    'actionUrl' => null,
    'defaultToday' => false,
])

@php
    use App\Enums\AccessibilityFeature;
    use App\Enums\DatePreset;
    use App\Enums\EventSort;
    use App\Enums\PriceFilter;
    use App\Enums\TimeOfDay;

    $formatter = app(\App\Support\DateFormatter::class);

    $destination = $actionUrl ?? route('events.index');
    $allDates = $defaultToday && request()->boolean('all_dates');
    $url = static function (\App\DTOs\EventFilters $set) use ($destination, $defaultToday, $allDates): string {
        $query = $set->toQueryString();

        if ($defaultToday && $allDates && ! $set->hasDateWindow()) {
            $query['all_dates'] = '1';
        }

        return $destination.($query === [] ? '' : '?'.http_build_query($query));
    };
    $anyDateUrl = $defaultToday
        ? $destination.'?'.http_build_query([...$filters->withPreset(null)->toQueryString(), 'all_dates' => '1'])
        : $url($filters->withPreset(null));

    $active = $filters->activeCount();

    $presets = [
        DatePreset::Today,
        DatePreset::Tonight,
        DatePreset::StartingSoon,
        DatePreset::Tomorrow,
        DatePreset::Weekend,
        DatePreset::Week,
    ];

    $prices = [PriceFilter::Free, PriceFilter::Donation, PriceFilter::Max10, PriceFilter::Max20];

    $available = static fn (string $group, string $value): bool => $counts === null || ($counts[$group][$value] ?? 0) > 0;
    $categories = collect($categories)->filter(fn ($value) => $filters->hasCategory($value->slug) || $available('category', $value->slug));
    $tags = collect($tags)->filter(fn ($value) => $filters->hasTag($value->slug) || $available('tag', $value->slug));
    $venues = collect($venues)->filter(fn ($value) => $filters->venue === $value->slug || $available('venue', $value->slug));
    $municipalities = collect($municipalities)->filter(fn ($value) => $filters->municipality === $value || $available('municipality', $value));
    $zones = collect($zones)->filter(fn ($value) => $filters->zone === $value || $available('zone', $value));
    $presets = array_filter($presets, fn ($value) => $filters->preset === $value || $available('date', $value->value));
    $prices = array_filter($prices, fn ($value) => $filters->price === $value || $available('price', $value->value));
    $priceOptions = array_filter(PriceFilter::options(), fn ($key) => $filters->price?->value === $key || $available('price', $key), ARRAY_FILTER_USE_KEY);
    $timeOptions = array_filter(TimeOfDay::options(), fn ($key) => $filters->time?->value === $key || $available('time', $key), ARRAY_FILTER_USE_KEY);

    $dateOptions = [];

    foreach ($presets as $preset) {
        $dateOptions[$preset->value] = $preset->label();
    }

    if ($filters->date !== null) {
        $dateOptions[$filters->date->format('Y-m-d')] = $formatter->weekdayDate($filters->date);
    }

    $venueOptions = [];

    foreach ($venues as $venue) {
        $venueOptions[$venue->slug] = $venue->name;
    }

    $municipalityOptions = [];

    foreach ($municipalities as $municipality) {
        $municipalityOptions[$municipality] = $municipality;
    }

    /* Finché nessun locale ha un quartiere, il menu non si disegna: una
       tendina con la sola voce «tutti» è un contenitore vuoto (§8.6). */
    $zoneOptions = [];

    foreach ($zones as $zone) {
        $zoneOptions[$zone] = $zone;
    }

    $tagOptions = [];

    foreach ($tags as $tag) {
        $tagOptions[$tag->slug] = $tag->name;
    }

@endphp
@if ($filters->budget !== null)
    <a data-filter-link class="ui-action inline-flex min-h-12 items-center border-2 border-accent p-3" href="{{ $destination.'?'.http_build_query(\Illuminate\Support\Arr::except($filters->toQueryString(), ['budget'])) }}">{{ __('tonight.up_to', ['amount' => $filters->budget]) }} ×</a>
@endif

<section data-filter-panel data-filter-empty="{{ __('map.empty_change') }}" data-filter-error="{{ __('tonight.count_error') }}" {{ $attributes->class(['flex flex-col gap-6']) }} aria-label="{{ __('filters.title') }}">
    <p data-filter-status role="status" hidden></p>
    {{--
        La colonna dei filtri del riferimento (D46): gruppi impilati, ognuno
        con la propria etichetta in maiuscoletto, e in fondo il conteggio dei
        risultati in giallo-verde.

        Restano tutti link e un modulo GET: accendere un filtro cambia
        l'indirizzo, che è l'unico stato della pagina. Cambia la disposizione,
        non il funzionamento — chi ha JavaScript spento filtra come prima.
    --}}
    <div class="flex items-baseline justify-between gap-2.5">
        <h2 class="m-0 font-display text-xl leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('filters.title') }}</h2>

        @if ($active > 0)
            <a
                href="{{ $destination }}"
                class="border-b-2 border-line font-display text-[0.594rem] leading-none font-extrabold tracking-[0.14em] text-ink-subtle uppercase transition-colors hover:border-accent hover:text-accent"
            >
                {{ __('filters.reset') }}
            </a>
        @endif
    </div>

    @if ($filters->tags !== [])
        <div class="flex flex-wrap gap-1.5" aria-label="{{ __('filters.tag.label') }}">
            @foreach ($filters->tags as $activeTag)
                <x-filter-chip
                    :href="$url($filters->withTags(array_values(array_diff($filters->tags, [$activeTag]))))"
                    :active="true"
                    :aria-label="__('filters.reset').' #'.($tagOptions[$activeTag] ?? $activeTag)"
                >
                    #{{ $tagOptions[$activeTag] ?? $activeTag }}
                </x-filter-chip>
            @endforeach
        </div>
    @endif

    <div class="flex flex-col gap-[9px]">
        <span class="font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-ink-subtle uppercase">{{ __('filters.date.label') }}</span>

        <div class="grid grid-cols-2 gap-0.5 bg-line p-0.5">
            @if ($filters->hasDateWindow() && $filters->preset === null)
                <x-filter-chip :href="$anyDateUrl" :active="true" class="col-span-2 justify-center">
                    {{ $filters->date?->format('d/m/Y') ?? ($filters->from?->format('d/m/Y').' – '.$filters->to?->format('d/m/Y')) }}
                </x-filter-chip>
            @endif
            @if (! $filters->hasDateWindow())
            <x-filter-chip
                :href="$anyDateUrl"
                :active="true"
                :removable="false"
                class="justify-center border-0 py-2.5"
            >
                {{ __('filters.date.any') }}
            </x-filter-chip>
            @endif

            @foreach ($presets as $preset)
                @continue($filters->hasDateWindow() && $filters->preset === null)
                @continue($filters->preset !== null && $filters->preset !== $preset)
                <x-filter-chip
                    :href="$filters->preset === $preset ? $anyDateUrl : $url($filters->withPreset($preset))"
                    :active="$filters->preset === $preset"
                    class="justify-center border-0 py-2.5"
                >
                    {{ $preset->label() }}
                </x-filter-chip>
            @endforeach
        </div>
    </div>

    @if (count($categories) > 0)
        <div class="flex flex-col gap-[9px]">
            <span class="font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-ink-subtle uppercase">{{ __('filters.category.label') }}</span>

            <div class="flex flex-wrap gap-1.5">
                @foreach ($categories as $category)
                    @continue($filters->categories !== [] && ! $filters->hasCategory($category->slug))
                    <x-filter-chip
                        :href="$url($filters->toggleCategory($category->slug))"
                        :active="$filters->hasCategory($category->slug)"
                    >
                        {{ $category->name }}
                    </x-filter-chip>
                @endforeach
            </div>
        </div>
    @endif

    @if ($prices !== [])
    <div class="flex flex-col gap-[9px]">
        <span class="font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-ink-subtle uppercase">{{ __('filters.price.label') }}</span>

        <div class="flex flex-wrap gap-1.5">
            @foreach ($prices as $price)
                @continue($filters->price !== null && $filters->price !== $price)
                <x-filter-chip
                    :href="$url($filters->price === $price ? $filters->withPrice(null) : $filters->withPrice($price))"
                    :active="$filters->price === $price"
                >
                    {{ $price->label() }}
                </x-filter-chip>
            @endforeach
        </div>
    </div>

    @endif
    @if ($timeOptions !== [])
    <div class="flex flex-col gap-[9px]">
        <span class="font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-ink-subtle uppercase">{{ __('filters.time.label') }}</span>

        <div class="flex flex-wrap gap-1.5">
            @foreach (TimeOfDay::cases() as $band)
                @continue($filters->time !== $band && ! $available('time', $band->value))
                @continue($filters->time !== null && $filters->time !== $band)
                <x-filter-chip
                    :href="$url($filters->time === $band ? $filters->withTime(null) : $filters->withTime($band))"
                    :active="$filters->time === $band"
                >
                    {{ $band->label() }}
                </x-filter-chip>
            @endforeach
        </div>
    </div>

    @endif
    @if ($filters->outdoor || $filters->accessible || $filters->family || $available('features', 'outdoor') || $available('features', 'accessible') || $available('features', 'family'))
    <div class="flex flex-col gap-[9px]">
        <span class="font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-ink-subtle uppercase">{{ __('filters.features.label') }}</span>

        <div class="flex flex-wrap gap-1.5">
            @php($hasFeature = $filters->outdoor || $filters->accessible || $filters->family)
            @if ($filters->outdoor || ((! $hasFeature || $counts !== null) && $available('features', 'outdoor')))
            <x-filter-chip :href="$url($filters->withOutdoor(! $filters->outdoor))" :active="$filters->outdoor">
                {{ __('filters.features.outdoor') }}
            </x-filter-chip>
            @endif

            @if ($filters->accessible || ((! $hasFeature || $counts !== null) && $available('features', 'accessible')))
            <x-filter-chip :href="$url($filters->withAccessible(! $filters->accessible))" :active="$filters->accessible">
                {{ __('filters.features.accessible') }}
            </x-filter-chip>
            @endif

            @if ($filters->family || ((! $hasFeature || $counts !== null) && $available('features', 'family')))
            <x-filter-chip :href="$url($filters->withFamily(! $filters->family))" :active="$filters->family">
                {{ __('filters.features.family') }}
            </x-filter-chip>
            @endif
        </div>
    </div>

    @endif
    @if ($total !== null)
        <div class="mt-auto flex flex-col gap-1 border-t-2 border-line pt-3.5">
            <span class="font-display text-[clamp(1.5rem,2vw,2.125rem)] leading-none font-extrabold tracking-[-0.03em] text-accent">{{ $total }}</span>
            <span class="font-display text-[0.594rem] leading-[1.3] font-extrabold tracking-[0.14em] text-ink-subtle uppercase">{{ trans_choice('filters.results', $total, ['count' => $total]) }}</span>
        </div>
    @endif

    <details data-filter-key="advanced" @if ($filters->membership !== null || $filters->venue !== null || $filters->municipality !== null || $filters->zone !== null || $filters->access !== [] || $filters->from !== null || $filters->to !== null) open @endif class="bg-canvas border-2 border-line">
        <summary class="min-h-12 cursor-pointer list-none px-4 py-3 text-base font-semibold text-ink">
            {{ __('filters.advanced') }} <span aria-hidden="true">⌄</span>
            @if ($active > 0)
                <span class="text-ink-subtle">{{ trans_choice('filters.active', $active, ['count' => $active]) }}</span>
            @endif
        </summary>

        <form method="GET" action="{{ $destination }}" class="flex flex-col gap-4 border-t border-line px-4 py-4">
            @if ($defaultToday)
                <input type="hidden" name="all_dates" value="1">
            @endif
            @foreach (['category' => implode(',', $filters->categories), 'lat' => $filters->lat, 'lng' => $filters->lng, 'q' => $filters->q, 'budget' => $filters->budget, 'discovery' => $filters->discovery ? '1' : null] as $hidden => $value)
                @if (filled($value))
                    <input type="hidden" name="{{ $hidden }}" value="{{ $value }}">
                @endif
            @endforeach

            <div data-advanced-filter-fields class="grid min-w-0 grid-cols-1 gap-5 [&>div]:min-w-0 [&_label]:text-base [&_select]:min-w-0 [&_select]:text-base [&_input]:min-w-0 [&_input]:text-base">
                <x-field
                    name="date"
                    :label="__('filters.date.label')"
                    :options="$dateOptions"
                    :placeholder-option="__('filters.date.any')"
                    :value="$filters->preset?->value ?? $filters->date?->format('Y-m-d')"
                />

                <x-field type="date" name="from" :label="__('filters.date.from')" :value="$filters->from?->format('Y-m-d')" />
                <x-field type="date" name="to" :label="__('filters.date.to')" :value="$filters->to?->format('Y-m-d')" />

                <x-field
                    name="tag"
                    :searchable="true"
                    :label="__('filters.tag.label')"
                    :options="$tagOptions"
                    :placeholder-option="__('filters.tag.any')"
                    :value="$filters->tags[0] ?? null"
                />

                <x-field
                    name="price"
                    :label="__('filters.price.label')"
                    :options="$priceOptions"
                    :placeholder-option="__('filters.price.any')"
                    :value="$filters->price?->value"
                />

                <x-field
                    name="time"
                    :label="__('filters.time_of_day.label')"
                    :options="$timeOptions"
                    :placeholder-option="__('filters.time_of_day.any')"
                    :value="$filters->time?->value"
                />

                <x-field
                    name="municipality"
                    :searchable="true"
                    :label="__('filters.place.municipality')"
                    :options="$municipalityOptions"
                    :placeholder-option="__('filters.place.any')"
                    :value="$filters->municipality"
                />

                @if ($zoneOptions !== [])
                    <x-field
                        name="zone"
                        :searchable="true"
                        :label="__('filters.place.zone')"
                        :options="$zoneOptions"
                        :placeholder-option="__('filters.place.any_zone')"
                        :value="$filters->zone"
                    />
                @endif

                <x-field
                    name="venue"
                    :searchable="true"
                    :label="__('filters.place.venue')"
                    :options="$venueOptions"
                    :placeholder-option="__('filters.place.any')"
                    :value="$filters->venue"
                />

                <x-field name="membership" :label="__('filters.membership.label')" :options="\App\Enums\MembershipRequirement::options()" :placeholder-option="__('filters.membership.any')" :value="$filters->membership?->value" />

                <x-field
                    name="sort"
                    :label="__('filters.sort.label')"
                    :options="array_filter(EventSort::options(), fn ($key) => $key !== EventSort::Distance->value || $filters->hasPosition(), ARRAY_FILTER_USE_KEY)"
                    :value="$filters->sort?->value ?? EventSort::Time->value"
                />

                @if ($filters->hasPosition())
                    <x-field
                        name="radius"
                        :label="__('filters.distance.label')"
                        :options="collect(config('eventi.distance_options'))->filter(fn (int $km): bool => $filters->radius === (float) $km || $available('radius', (string) $km))->mapWithKeys(fn (int $km): array => [$km => __('filters.distance.radius', ['km' => $km])])->all()"
                        :value="$filters->radius === null ? null : (string) (int) $filters->radius"
                    />
                @endif
            </div>

            <fieldset class="flex min-w-0 flex-col gap-2">
                <legend class="mb-2 text-sm font-semibold text-ink">{{ __('filters.features.label') }}</legend>

                @foreach (['outdoor' => __('filters.features.outdoor'), 'accessible' => __('filters.features.accessible'), 'family' => __('filters.features.family')] as $flag => $label)
                    @continue(! $filters->{$flag} && ! $available('features', $flag))
                    <label class="flex min-h-12 cursor-pointer items-center gap-3 text-base text-ink">
                        <input
                            type="checkbox"
                            name="{{ $flag }}"
                            value="1"
                            @checked($filters->{$flag})
                            class="size-5 shrink-0 rounded border-line text-brand focus:ring-focus"
                        >
                        {{ $label }}
                    </label>
                @endforeach
            </fieldset>

            {{-- Le voci di accessibilità: esistono come filtro soltanto
                 perché `venues.accessibility` è strutturato. Sono in AND, e
                 il modulo lo dice mostrandole come caselle e non come
                 alternative. --}}
            <fieldset class="flex min-w-0 flex-col gap-2">
                <legend class="mb-2 text-sm font-semibold text-ink">{{ __('filters.accessibility.label') }}</legend>

                @foreach (AccessibilityFeature::cases() as $feature)
                    @continue(! $filters->hasAccess($feature->value) && ! $available('access', $feature->value))
                    <label class="flex min-h-12 cursor-pointer items-center gap-3 text-base text-ink">
                        <input
                            type="checkbox"
                            name="access[]"
                            value="{{ $feature->value }}"
                            @checked($filters->hasAccess($feature->value))
                            class="size-5 shrink-0 rounded border-line text-brand focus:ring-focus"
                        >
                        {{ $feature->label() }}
                    </label>
                @endforeach
            </fieldset>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    class="min-h-12 bg-brand px-4 py-2.5 font-display text-sm leading-tight font-extrabold tracking-wide text-on-brand uppercase transition hover:bg-brand-strong"
                >
                    {{ __('filters.apply') }}
                </button>

                @if ($active > 0)
                    <a href="{{ $destination }}" class="text-sm font-semibold text-ink-muted underline hover:text-ink">
                        {{ __('filters.reset') }}
                    </a>
                @endif

                @if ($total !== null)
                    <span class="ml-auto text-sm text-ink-subtle">{{ trans_choice('filters.results', $total, ['count' => $total]) }}</span>
                @endif
            </div>
        </form>
    </details>
</section>
