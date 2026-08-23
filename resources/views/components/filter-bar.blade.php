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
    'venues',
    'total' => null,
])

@php
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

    $tagOptions = [];

    foreach ($tags as $tag) {
        $tagOptions[$tag->slug] = $tag->name;
    }
@endphp

<section {{ $attributes->class(['flex flex-col gap-3']) }} aria-label="{{ __('filters.title') }}">
    <div class="scroll-row gap-2">
        <x-filter-chip :href="$url($filters->withPreset(null))" :active="! $filters->hasDateWindow()">
            {{ __('filters.date.any') }}
        </x-filter-chip>

        @foreach ($presets as $preset)
            <x-filter-chip
                :href="$url($filters->preset === $preset ? $filters->withPreset(null) : $filters->withPreset($preset))"
                :active="$filters->preset === $preset"
            >
                {{ $preset->label() }}
            </x-filter-chip>
        @endforeach
    </div>

    <div class="scroll-row gap-2">
        @foreach ($categories as $category)
            <x-filter-chip
                :href="$url($filters->toggleCategory($category->slug))"
                :active="$filters->hasCategory($category->slug)"
            >
                {{ $category->name }}
            </x-filter-chip>
        @endforeach
    </div>

    <div class="scroll-row gap-2">
        @foreach ($prices as $price)
            <x-filter-chip
                :href="$url($filters->price === $price ? $filters->withPrice(null) : $filters->withPrice($price))"
                :active="$filters->price === $price"
            >
                {{ $price->label() }}
            </x-filter-chip>
        @endforeach

        @foreach (TimeOfDay::cases() as $band)
            <x-filter-chip
                :href="$url($filters->time === $band ? $filters->withTime(null) : $filters->withTime($band))"
                :active="$filters->time === $band"
            >
                {{ $band->label() }}
            </x-filter-chip>
        @endforeach

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

    <details class="rounded-card bg-surface ring-1 ring-line" @if ($active > 0) open @endif>
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

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    class="rounded-pill bg-brand px-4 py-2 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
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
