{{--
    La mappa degli eventi (§11.6).

    La pagina esiste **anche senza la mappa**: sotto al riquadro c'è l'elenco
    degli stessi eventi, con gli stessi filtri della lista. Chi ha JavaScript
    spento, chi sta su una rete che non fa passare le tessere e chi legge con
    uno screen reader trovano il contenuto, non un rettangolo vuoto.

    I marcatori sono **uno per locale**: due concerti nello stesso circolo
    hanno le stesse coordinate, e disegnati come due punti resterebbero
    sovrapposti a qualunque ingrandimento.
--}}
@php
    $center = ['lng' => (float) $city->center_lng, 'lat' => (float) $city->center_lat];
    $bounds = is_array($city->bounds) ? $city->bounds : null;

    $configuration = [
        'style' => config('map.style'),
        'attribution' => __('map.attribution', [
            'osm' => '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">'.e(__('ui.footer.osm')).'</a>',
            'license' => '<a href="https://opendatacommons.org/licenses/odbl/" target="_blank" rel="noopener noreferrer">'.e(__('ui.footer.odbl')).'</a>',
            'tiles' => '<a href="https://openfreemap.org/" target="_blank" rel="noopener noreferrer">'.e(__('map.tiles')).'</a>',
        ]),
        'center' => [$center['lng'], $center['lat']],
        'zoom' => (int) $city->default_zoom,
        'bounds' => $bounds,
        'panThreshold' => (float) config('map.pan_threshold'),
        'fallbackColor' => config('map.fallback_color'),
        'endpoints' => [
            'markers' => route('map.markers', $filters->toQueryString()),
            /* L'indirizzo della card si compone di una radice e di una coda:
               in mezzo va il numero del locale. Ricostruirlo in JavaScript
               significherebbe scrivere due volte lo stesso percorso, e
               dimenticarsene una quando cambia il prefisso della città. */
            'venue' => [
                'base' => \Illuminate\Support\Str::beforeLast(route('map.venue', ['venue' => 0]), '0'),
                'query' => $filters->toQueryString() === [] ? '' : '?'.http_build_query($filters->toQueryString()),
            ],
        ],
        'labels' => [
            'searchHere' => __('map.search_here'),
            'searching' => __('map.searching'),
            'error' => __('common.error'),
        ],
        'payload' => $payload,
    ];
@endphp

<x-layouts.app :meta="$meta">
    <x-slot:head>
        @vite('resources/js/map.js')
    </x-slot:head>

    <header class="flex flex-col gap-2">
        <h1 class="text-balance text-hero text-ink">{{ $meta->heading }}</h1>
        <p class="max-w-prose text-sm text-ink-muted">{{ $meta->description }}</p>
    </header>

    <div class="mt-6">
        <x-filter-bar
            :filters="$filters"
            :categories="$categories"
            :tags="$tags"
            :municipalities="$municipalities"
            :venues="$venues"
        />
    </div>

    <div class="mt-4">
        <x-near-me :filters="$filters" :action="route('map.index')" />
    </div>

    <section class="mt-6" aria-label="{{ __('map.label') }}">
        <div class="relative overflow-hidden rounded-card ring-1 ring-line" data-map-shell>
            <div
                id="mappa"
                data-map
                class="h-[60vh] min-h-80 w-full bg-surface-sunken"
                role="application"
                aria-label="{{ __('map.label') }}"
            >
                <p class="flex h-full items-center justify-center px-6 text-center text-sm text-ink-subtle" data-map-placeholder>
                    {{ __('map.unavailable') }}
                </p>
            </div>

            {{-- "Cerca in quest'area": compare solo dopo che la mappa è stata
                 spostata davvero, e solo se il JavaScript è vivo. --}}
            <button
                type="button"
                data-map-search
                hidden
                class="absolute inset-x-0 top-3 z-10 mx-auto w-max rounded-pill bg-brand px-4 py-2 text-sm font-semibold text-on-brand shadow-lift transition hover:bg-brand-strong"
            >
                {{ __('map.search_here') }}
            </button>

            {{-- Foglio inferiore con la card dell'evento (§11.6). Sul telefono
                 sale dal basso, da tablet in su resta un pannello laterale. --}}
            <div
                data-map-sheet
                hidden
                role="dialog"
                aria-label="{{ __('map.sheet_label') }}"
                class="absolute inset-x-0 bottom-0 z-20 max-h-[70%] overflow-y-auto rounded-t-card bg-surface p-4 shadow-lift ring-1 ring-line sm:inset-y-0 sm:right-0 sm:left-auto sm:w-96 sm:max-h-none sm:rounded-t-none sm:rounded-l-card"
            >
                <button
                    type="button"
                    data-map-sheet-close
                    class="float-right -mt-1 rounded-pill px-2 py-1 text-sm font-semibold text-ink-muted hover:text-ink"
                >
                    <span class="sr-only">{{ __('map.close_sheet') }}</span>
                    <span aria-hidden="true">&times;</span>
                </button>

                <div data-map-sheet-body></div>
            </div>
        </div>

        <p class="mt-2 text-xs text-ink-subtle" data-map-truncated @if (! $payload['truncated']) hidden @endif>
            {{ __('map.truncated') }}
        </p>

        @if ($payload['categories'] !== [])
            <div class="mt-3">
                <h2 class="sr-only">{{ __('map.legend') }}</h2>
                <ul class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-ink-muted" data-map-legend>
                    @foreach ($payload['categories'] as $category)
                        <li class="flex items-center gap-1.5">
                            <span aria-hidden="true" class="size-2.5 rounded-pill" style="background-color: {{ $category['color'] }}"></span>
                            {{ $category['name'] }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="mt-3 text-xs text-ink-subtle">
            {!! __('map.attribution', [
                'osm' => '<a class="underline hover:text-ink" href="https://www.openstreetmap.org/copyright" rel="noopener noreferrer" target="_blank">'.e(__('ui.footer.osm')).'</a>',
                'license' => '<a class="underline hover:text-ink" href="https://opendatacommons.org/licenses/odbl/" rel="noopener noreferrer" target="_blank">'.e(__('ui.footer.odbl')).'</a>',
                'tiles' => '<a class="underline hover:text-ink" href="https://openfreemap.org/" rel="noopener noreferrer" target="_blank">'.e(__('map.tiles')).'</a>',
            ]) !!}
        </p>
    </section>

    {{-- Gli stessi eventi, in elenco: è ciò che rende la pagina leggibile
         senza JavaScript e ciò che un motore di ricerca trova qui dentro. --}}
    @if ($occurrences->isNotEmpty())
        <section class="mt-section" aria-labelledby="mappa-elenco">
            <x-section-heading
                id="mappa-elenco"
                :title="__('map.fallback_title')"
                tone="neutral"
                :href="route('events.index', $filters->toQueryString())"
                :link-label="__('map.fallback_link')"
            />

            <x-event-grid :occurrences="$occurrences" />
        </section>
    @endif

    <script type="application/json" data-map-config>@json($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
</x-layouts.app>
