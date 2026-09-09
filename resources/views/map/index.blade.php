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
<x-layouts.app :meta="$meta" :wide="true">
    <x-slot:head>
        @vite('resources/js/map.js')
    </x-slot:head>

    {{--
        Due colonne: i filtri stretti a sinistra, la mappa a tutta altezza a
        destra. Prima erano impilati — intestazione, poi tutti i filtri, poi la
        mappa — e su questa pagina la mappa finiva sotto la piega: chi apre
        `/mappa` vuole vedere una mappa, non l'elenco dei modi per restringerla.
    --}}
    <header class="flex flex-col gap-2 bg-canvas px-gutter py-5 lg:hidden">
        <h1 class="m-0 font-display text-[clamp(1.5rem,2.4vw,2.25rem)] leading-[0.96] font-extrabold tracking-[-0.04em] text-balance uppercase">{{ $meta->heading }}</h1>
        <p class="m-0 text-[0.813rem] leading-[1.5] text-ink-muted">{{ $meta->description }}</p>
    </header>

    <div data-event-browser data-result-count="{{ $occurrences->count() }}" class="grid items-start gap-0.5 bg-line lg:[grid-template-columns:minmax(240px,300px)_minmax(0,1fr)]">
        <aside class="order-2 flex flex-col gap-6 overflow-y-auto bg-canvas p-[clamp(1rem,1.6vw,1.375rem)] lg:order-1 lg:sticky lg:top-header lg:max-h-below-header">
            <header class="hidden flex-col gap-2 lg:flex">
                <h1 class="m-0 font-display text-[clamp(1.5rem,2.4vw,2.25rem)] leading-[0.96] font-extrabold tracking-[-0.04em] text-balance uppercase">{{ $meta->heading }}</h1>
                <p class="m-0 text-[0.813rem] leading-[1.5] text-ink-muted">{{ $meta->description }}</p>
            </header>

            <x-filter-bar
                :counts="$facetCounts"
                :filters="$filters"
                :categories="$categories"
                :tags="$tags"
                :municipalities="$municipalities"
                :zones="$zones"
                :venues="$venues"
                :action-url="route('map.index')"
                :default-today="true"
            />

            <x-near-me :filters="$filters" :counts="$facetCounts" :action="route('map.index')" />
        </aside>

        <section aria-label="{{ __('map.label') }}" class="order-1 flex flex-col bg-canvas lg:order-2 lg:sticky lg:top-header lg:h-below-header">
            <x-events-map
                :city="$city"
                :filters="$filters"
                :payload="$payload"
                class="flex-auto"
                map-class="h-full min-h-[24rem] w-full"
            />
        </section>
    {{-- Gli stessi eventi, in elenco: è ciò che rende la pagina leggibile
         senza JavaScript e ciò che un motore di ricerca trova qui dentro. --}}
    @if ($occurrences->isNotEmpty())
        <section aria-labelledby="mappa-elenco" class="order-3 bg-canvas lg:col-span-2">
            <div class="flex flex-wrap items-end justify-between gap-5 px-gutter pt-[clamp(1.5rem,2.8vw,2.75rem)] pb-[clamp(1.125rem,2vw,1.625rem)]">
                <h2 id="mappa-elenco" class="m-0 font-display text-[clamp(1.5rem,3.2vw,3.125rem)] leading-[0.96] font-extrabold tracking-[-0.04em] uppercase">
                    {{ __('map.fallback_title') }}
                </h2>

                <a
                    href="{{ route('events.index', $filters->toQueryString()) }}"
                    class="inline-flex items-center gap-2 border-b-2 border-accent pb-[5px] font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:text-accent"
                >
                    {{ __('map.fallback_link') }}
                    <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="square"><path d="M5 12h14M13 5l7 7-7 7"></path></svg>
                </a>
            </div>

            <x-event-grid :occurrences="$occurrences" class="border-t-2 border-line" />
        </section>
    @endif
    </div>

</x-layouts.app>
