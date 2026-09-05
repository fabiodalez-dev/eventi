{{--
    Il riquadro della mappa, con tutto ciò che gli sta attorno (§11.6).

    Sta in un componente perché lo usano tre pagine — la pagina iniziale nella
    sezione «vicino a te», la lista come terza colonna, e la mappa a schermo
    intero — e una configurazione scritta tre volte diverge alla prima modifica
    dell'indirizzo dei marcatori.

    **Se lo script non parte, qui non succede niente di grave**: chi lo usa
    mette sotto l'elenco degli stessi eventi. Il riquadro resta con la sua
    frase, e la pagina si legge lo stesso.

    I marcatori sono **uno per locale**: due concerti nello stesso circolo
    hanno le stesse coordinate, e disegnati come due punti resterebbero
    sovrapposti a qualunque ingrandimento.
--}}
@props([
    'city',
    'filters',
    'payload',
    /* L'altezza del riquadro la decide chi lo ospita: a schermo intero è una
       cosa, dentro una colonna che segue lo scorrimento è un'altra. Si chiama
       `mapClass` e non `class` perché `class` è già l'attributo del
       contenitore esterno, e dichiararlo fra le proprietà lo sottrarrebbe a
       chi vuole dare una classe al componente. */
    'mapClass' => 'h-[60vh] min-h-80',
    /* La nota su «troppi risultati» sotto al riquadro. Nella colonna stretta
       della lista non ci sta, e non è un'informazione che si perde: la lista
       accanto dice già quanti sono. */
    'showLegend' => true,
    /* Un riquadro fermo mostra un punto e basta: è la mappa di un locale sulla
       sua scheda, dove trascinare e ingrandire non serve a niente e rubare lo
       scorrimento della pagina a chi ci passa sopra col dito è solo un
       fastidio. */
    'static' => false,
    /*
     * Dove guardare. Senza, si guarda il centro della città con lo zoom della
     * città — giusto per una mappa di tutti gli eventi, sbagliato per il
     * riquadro di un locale: se quel locale sta in provincia, resta fuori
     * inquadratura e il riquadro sembra vuoto.
     *
     * `[lng, lat]`, come ovunque nel sistema.
     */
    'center' => null,
    'zoom' => null,
])

@php
    $attribution = __('map.attribution', [
        'osm' => '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">'.e(__('ui.footer.osm')).'</a>',
        'license' => '<a href="https://opendatacommons.org/licenses/odbl/" target="_blank" rel="noopener noreferrer">'.e(__('ui.footer.odbl')).'</a>',
        'tiles' => '<a href="https://openfreemap.org/" target="_blank" rel="noopener noreferrer">'.e(__('map.tiles')).'</a>',
    ]);

    $configuration = [
        'static' => $static,
        'style' => config('map.style_url'),
        'maxZoom' => config()->integer('map.max_zoom'),
        'attribution' => $attribution,
        /* Ordine `[lng, lat]`, come GeoJSON e come il database. */
        'center' => $center ?? [(float) $city->center_lng, (float) $city->center_lat],
        'zoom' => $zoom ?? (int) $city->default_zoom,
        /* I confini della città inquadrano tutta la città: su un riquadro
           centrato su un singolo locale rifarebbero lo zoom indietro,
           annullando il centro appena scelto. */
        'bounds' => $center === null && is_array($city->bounds) ? $city->bounds : null,
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
            'locate' => __('map.locate'),
        ],
        'payload' => $payload,
    ];
@endphp

<div {{ $attributes->class(['flex flex-col']) }}>
    <div class="relative flex-auto overflow-hidden" data-map-shell>
        {{-- La configurazione resta accanto al riquadro: una pagina può avere
             più mappe e ognuna deve trovare la propria. --}}
        <script type="application/json" data-map-config>@json($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>

        <div
            id="mappa"
            data-map
            class="{{ $mapClass }} w-full bg-surface-sunken"
            role="application"
            aria-label="{{ __('map.label') }}"
        >
            <p class="flex h-full items-center justify-center px-6 text-center text-[0.813rem] text-ink-subtle" data-map-placeholder>
                {{ __('map.unavailable') }}
            </p>
        </div>

        <ul class="sr-only" data-map-marker-list aria-label="{{ __('map.label') }}"></ul>

        {{-- "Cerca in quest'area": compare solo dopo che la mappa è stata
             spostata davvero, e solo se il JavaScript è vivo. --}}
        <button
            type="button"
            data-map-search
            hidden
            class="absolute inset-x-0 top-3 z-[1000] mx-auto w-max bg-accent px-4 py-2.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase"
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
            {{--
                Due dettagli, entrambi costati una diagnosi.

                `z-[1100]`: Leaflet dispone i propri pannelli fra 200 e 700 e i
                propri controlli a 1000. Con `z-20` il foglio si apriva davvero
                — `hidden` veniva tolto, il contenuto arrivava — ma restava
                DIETRO le tessere: cliccare un punto non mostrava niente, e
                sembrava che il click non funzionasse.

                `fixed` e non `absolute`: ancorato al riquadro, il foglio
                seguiva le sorti del contenitore, e quando lo scorrimento
                portava il riquadro sotto la testata fissa il suo bordo
                superiore — con il nome del locale e il pulsante di chiusura —
                finiva coperto. Ancorato al viewport è sempre intero, qualunque
                cosa faccia la pagina sotto.
            --}}
            class="fixed inset-x-0 bottom-0 z-[1100] max-h-[70%] overflow-y-auto border-t-2 border-line bg-canvas p-4 sm:top-header sm:right-0 sm:left-auto sm:max-h-none sm:w-96 sm:border-t-0 sm:border-l-2"
        >
            <button
                type="button"
                data-map-sheet-close
                class="float-right -mt-1 px-2 py-1 font-display text-sm font-extrabold text-ink-muted hover:text-accent"
            >
                <span class="sr-only">{{ __('map.close_sheet') }}</span>
                <span aria-hidden="true">&times;</span>
            </button>

            <div data-map-sheet-body></div>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 border-t-2 border-line px-4 py-2 text-[0.625rem] text-ink-subtle">
        <p>{{ $static ? __('map.pin_hint') : __('map.cluster_hint') }}</p>
        <p>{!! $attribution !!}</p>
    </div>

    @if ($showLegend)
        <p class="border-t-2 border-line px-4 py-2 text-[0.688rem] text-ink-subtle" data-map-truncated @if (! $payload['truncated']) hidden @endif>
            {{ __('map.truncated') }}
        </p>

        {{-- Qui stava la legenda dei colori per categoria. Non c'è più perché
             i punti hanno tutti lo stesso colore: una legenda che spiega una
             sola tinta non spiega niente, e dieci tinte accese su una mappa in
             scala di grigi litigavano con l'unico accento della tavolozza. Chi
             vuole vedere una sola categoria la filtra — ed è una risposta
             migliore, perché toglie di mezzo tutto il resto invece di
             chiedere di distinguere un rosa da un fucsia. --}}
    @endif

</div>
