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
    'categories',
    'tags',
    'municipalities',
    'zones' => [],
    'venues',
    'total' => null,
])

@php
    use App\Enums\AccessibilityFeature;
    use App\Enums\DatePreset;
    use App\Enums\EventSort;
    use App\Enums\PriceFilter;
    use App\Enums\TimeOfDay;

    $formatter = app(\App\Support\DateFormatter::class);

    /* Ogni pillola è semplicemente un altro insieme di filtri, reso indirizzo. */
    $url = static fn (\App\DTOs\EventFilters $set): string => route('events.index', $set->toQueryString());

    $active = $filters->activeCount();

    $presets = [
        DatePreset::Today,
        DatePreset::Tonight,
        DatePreset::Tomorrow,
        DatePreset::Weekend,
        DatePreset::Week,
    ];

    $prices = [PriceFilter::Free, PriceFilter::Donation, PriceFilter::Max10, PriceFilter::Max20];

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

<section {{ $attributes->class(['flex flex-col gap-6']) }} aria-label="{{ __('filters.title') }}">
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
                href="{{ route('events.index') }}"
                class="border-b-2 border-line font-display text-[0.594rem] leading-none font-extrabold tracking-[0.14em] text-ink-subtle uppercase transition-colors hover:border-accent hover:text-accent"
            >
                {{ __('filters.reset') }}
            </a>
        @endif
    </div>

    <div class="flex flex-col gap-[9px]">
        <span class="font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-ink-subtle uppercase">{{ __('filters.date.label') }}</span>

        <div class="grid grid-cols-2 gap-0.5 bg-line p-0.5">
            <x-filter-chip
                :href="$url($filters->withPreset(null))"
                :active="! $filters->hasDateWindow()"
                class="justify-center border-0 py-2.5"
            >
                {{ __('filters.date.any') }}
            </x-filter-chip>

            @foreach ($presets as $preset)
                <x-filter-chip
                    :href="$url($filters->preset === $preset ? $filters->withPreset(null) : $filters->withPreset($preset))"
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

    <div class="flex flex-col gap-[9px]">
        <span class="font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-ink-subtle uppercase">{{ __('filters.price.label') }}</span>

        <div class="flex flex-wrap gap-1.5">
            @foreach ($prices as $price)
                <x-filter-chip
                    :href="$url($filters->price === $price ? $filters->withPrice(null) : $filters->withPrice($price))"
                    :active="$filters->price === $price"
                >
                    {{ $price->label() }}
                </x-filter-chip>
            @endforeach
        </div>
    </div>

    <div class="flex flex-col gap-[9px]">
        <span class="font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-ink-subtle uppercase">{{ __('filters.time.label') }}</span>

        <div class="flex flex-wrap gap-1.5">
            @foreach (TimeOfDay::cases() as $band)
                <x-filter-chip
                    :href="$url($filters->time === $band ? $filters->withTime(null) : $filters->withTime($band))"
                    :active="$filters->time === $band"
                >
                    {{ $band->label() }}
                </x-filter-chip>
            @endforeach
        </div>
    </div>

    <div class="flex flex-col gap-[9px]">
        <span class="font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-ink-subtle uppercase">{{ __('filters.features.label') }}</span>

        <div class="flex flex-wrap gap-1.5">
            <x-filter-chip :href="$url($filters->withOutdoor(! $filters->outdoor))" :active="$filters->outdoor">
                {{ __('filters.features.outdoor') }}
            </x-filter-chip>

            <x-filter-chip :href="$url($filters->withAccessible(! $filters->accessible))" :active="$filters->accessible">
                {{ __('filters.features.accessible') }}
            </x-filter-chip>

            <x-filter-chip :href="$url($filters->withFamily(! $filters->family))" :active="$filters->family">
                {{ __('filters.features.family') }}
            </x-filter-chip>
        </div>
    </div>

    @if ($total !== null)
        <div class="mt-auto flex flex-col gap-1 border-t-2 border-line pt-3.5">
            <span class="font-display text-[clamp(1.5rem,2vw,2.125rem)] leading-none font-extrabold tracking-[-0.03em] text-accent">{{ $total }}</span>
            <span class="font-display text-[0.594rem] leading-[1.3] font-extrabold tracking-[0.14em] text-ink-subtle uppercase">{{ trans_choice('filters.results', $total, ['count' => $total]) }}</span>
        </div>
    @endif

    <details class="bg-canvas border-2 border-line" @if ($active > 0) open @endif>
        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-ink">
            {{ __('filters.open') }}
            @if ($active > 0)
                <span class="text-ink-subtle">{{ trans_choice('filters.active', $active, ['count' => $active]) }}</span>
            @endif
        </summary>

        <form method="GET" action="{{ route('events.index') }}" class="flex flex-col gap-4 border-t border-line px-4 py-4">
            @foreach (['category' => implode(',', $filters->categories), 'lat' => $filters->lat, 'lng' => $filters->lng, 'q' => $filters->q] as $hidden => $value)
                @if (filled($value))
                    <input type="hidden" name="{{ $hidden }}" value="{{ $value }}">
                @endif
            @endforeach

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
                    :label="__('filters.tag.label')"
                    :options="$tagOptions"
                    :placeholder-option="__('filters.tag.any')"
                    :value="$filters->tags[0] ?? null"
                />

                <x-field
                    name="price"
                    :label="__('filters.price.label')"
                    :options="\App\Enums\PriceFilter::options()"
                    :placeholder-option="__('filters.price.any')"
                    :value="$filters->price?->value"
                />

                <x-field
                    name="time"
                    :label="__('filters.time_of_day.label')"
                    :options="TimeOfDay::options()"
                    :placeholder-option="__('filters.time_of_day.any')"
                    :value="$filters->time?->value"
                />

                <x-field
                    name="municipality"
                    :label="__('filters.place.municipality')"
                    :options="$municipalityOptions"
                    :placeholder-option="__('filters.place.any')"
                    :value="$filters->municipality"
                />

                @if ($zoneOptions !== [])
                    <x-field
                        name="zone"
                        :label="__('filters.place.zone')"
                        :options="$zoneOptions"
                        :placeholder-option="__('filters.place.any_zone')"
                        :value="$filters->zone"
                    />
                @endif

                <x-field
                    name="venue"
                    :label="__('filters.place.venue')"
                    :options="$venueOptions"
                    :placeholder-option="__('filters.place.any')"
                    :value="$filters->venue"
                />

                <x-field
                    name="sort"
                    :label="__('filters.sort.label')"
                    :options="EventSort::options()"
                    :value="$filters->sort?->value ?? EventSort::Time->value"
                />

                @if ($filters->hasPosition())
                    <x-field
                        name="radius"
                        :label="__('filters.distance.label')"
                        :options="collect(config('eventi.distance_options'))->mapWithKeys(fn (int $km): array => [$km => __('filters.distance.radius', ['km' => $km])])->all()"
                        :value="$filters->radius === null ? null : (string) (int) $filters->radius"
                    />
                @endif
            </div>

            <fieldset class="flex flex-wrap gap-4">
                <legend class="mb-2 text-sm font-semibold text-ink">{{ __('filters.features.label') }}</legend>

                @foreach (['outdoor' => __('filters.features.outdoor'), 'accessible' => __('filters.features.accessible'), 'family' => __('filters.features.family')] as $flag => $label)
                    <label class="flex items-center gap-2 text-sm text-ink-muted">
                        <input
                            type="checkbox"
                            name="{{ $flag }}"
                            value="1"
                            @checked($filters->{$flag})
                            class="size-4 rounded border-line text-brand focus:ring-focus"
                        >
                        {{ $label }}
                    </label>
                @endforeach
            </fieldset>

            {{-- Le voci di accessibilità: esistono come filtro soltanto
                 perché `venues.accessibility` è strutturato. Sono in AND, e
                 il modulo lo dice mostrandole come caselle e non come
                 alternative. --}}
            <fieldset class="flex flex-wrap gap-4">
                <legend class="mb-2 text-sm font-semibold text-ink">{{ __('filters.accessibility.label') }}</legend>

                @foreach (AccessibilityFeature::cases() as $feature)
                    <label class="flex items-center gap-2 text-sm text-ink-muted">
                        <input
                            type="checkbox"
                            name="access[]"
                            value="{{ $feature->value }}"
                            @checked($filters->hasAccess($feature->value))
                            class="size-4 rounded border-line text-brand focus:ring-focus"
                        >
                        {{ $feature->label() }}
                    </label>
                @endforeach
            </fieldset>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    class="bg-brand px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-brand uppercase transition hover:bg-brand-strong"
                >
                    {{ __('filters.apply') }}
                </button>

                @if ($active > 0)
                    <a href="{{ route('events.index') }}" class="text-sm font-semibold text-ink-muted underline hover:text-ink">
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
