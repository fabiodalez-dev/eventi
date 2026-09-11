{{--
    La lista degli eventi (§11.3), disposta come il riferimento (D46): filtri a
    sinistra, risultati al centro, mappa a destra. Le due colonne laterali
    seguono lo scorrimento; scorre solo quella in mezzo.

    Tutto lo stato della pagina sta nell'indirizzo: i filtri sono link, la
    paginazione è `?page=`, e l'infinite scroll è solo un miglioramento che il
    JavaScript aggiunge sopra a una paginazione che funziona da sola. Chi
    naviga senza JavaScript ha una pagina completa, non una versione ridotta.

    **Sotto i 1024px le tre colonne diventano una, e l'ordine è quello del
    documento: risultati, filtri, mappa.**

    Prima l'ordine era quello del desktop — filtri, risultati, mappa — perché
    era quello del sorgente e nessuno l'aveva scelto per il telefono: a 412px
    il pannello è alto **1230 pixel**, cioè una schermata e mezza di strumenti
    prima del titolo della pagina e quasi due prima del primo evento. Chi
    arriva da una ricerca su `/eventi/oggi` o `/eventi/categoria/musica` ha già
    filtrato — sta nell'indirizzo — e riceveva il modo di rifarlo al posto di
    ciò che ha chiesto. Contro il principio §11: il contenuto utile per primo.

    **L'ordine è cambiato nel DOM e non con `order`**, che è la correzione da
    una riga e quella sbagliata. `order` sposta i pixel e lascia il documento
    dov'è: la tabulazione da tastiera continuerebbe a entrare nei filtri —
    adesso disegnati in fondo alla pagina, quindi con il fuoco fuori dallo
    schermo, che è WCAG 2.4.3 livello A — e uno screen reader leggerebbe le 45
    pillole prima dell'`<h1>` esattamente come prima. Sistemerebbe la pagina
    per chi scorre col pollice e la peggiorerebbe per tutti gli altri.

    Sul desktop il posizionamento esplicito di griglia rimette le colonne dove
    stavano, al pixel. Resta uno scarto fra ordine visivo e ordine di
    tabulazione, ma nella direzione benigna: sono due regioni indipendenti e
    nominate, non i campi di un modulo dove la sequenza porta significato, e
    «prima il contenuto, poi la barra laterale» è il verso giusto dei due.
--}}
<x-layouts.app :meta="$meta" :wide="true">
    <div data-event-browser data-result-count="{{ $occurrences->total() }}">
    @if ($filters->discovery)
        <p class="border-b-2 border-line p-gutter text-ink-muted">{{ __('tonight.filtered') }}</p>
    @endif
    @if (($taxonomy ?? null) && request()->integer('page', 1) === 1)
        <div class="px-gutter"><x-editorial-content :model="$taxonomy" /></div>
    @endif
    <x-slot:head>
        <x-json-ld :data="$structuredData" />
        @vite('resources/js/map.js')
    </x-slot:head>

    <div class="grid items-start gap-0.5 bg-line lg:[grid-template-columns:minmax(232px,268px)_minmax(0,1.32fr)_minmax(0,1fr)]">
        <section class="bg-canvas lg:col-start-2 lg:row-start-1 lg:min-h-below-header">
            <div class="catalog-heading-panel flex flex-col gap-4 border-b-2 border-line px-[clamp(1rem,1.8vw,1.625rem)] py-[clamp(1.125rem,2.2vw,1.875rem)]">
                <div class="flex items-center gap-2.5">
                    <span aria-hidden="true" class="size-2 bg-accent blink-dot"></span>
                    <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-ink-muted uppercase">
                        {{ trans_choice('filters.results', $occurrences->total(), ['count' => $occurrences->total()]) }}
                    </span>
                </div>

                <h1 class="m-0 font-display text-[clamp(1.875rem,3.6vw,3.625rem)] leading-[0.94] font-extrabold tracking-[-0.04em] text-balance uppercase">
                    {{ $meta->heading }}
                </h1>

                @if ($meta->description)
                    <p class="m-0 max-w-prose text-[0.813rem] leading-[1.5] text-ink-muted">{{ $meta->description }}</p>
                @endif

                {{-- Su telefono il pannello sta sotto ai risultati: questo è
                     ciò che lo tiene a un tocco invece che a una scrollata.
                     Sul desktop la colonna è già lì accanto e il collegamento
                     sparisce. È un'ancora, non un comando: funziona anche
                     senza JavaScript. --}}
                <a
                    href="#filtri"
                    data-filter-jump
                    class="ui-action inline-flex min-h-12 w-fit items-center gap-2 border-2 border-line px-3.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:border-accent hover:text-accent lg:hidden"
                >
                    {{ __('filters.jump') }}
                    <span aria-hidden="true">↓</span>
                </a>
            </div>

            @if ($occurrences->total() > 0)
                {{-- Il contenitore dei risultati è ciò che l'infinite scroll
                     estende: l'attributo lo dichiara, e senza JavaScript non fa
                     niente. --}}
                {{-- La campagna in cima, quando c'è: sopra i risultati e fuori
                     dal contenitore che l'infinite scroll estende, altrimenti
                     ricomparirebbe a ogni pagina caricata. --}}
                @if ($sponsorship !== null && $sponsoredOccurrence !== null && ! $filters->discovery)
                    <x-sponsored-card
                        :sponsorship="$sponsorship"
                        :occurrence="$sponsoredOccurrence"
                        class="border-b-2 border-line"
                        level="h2"
                    />
                @endif

                <div data-results>
                    <x-event-grid
                        :occurrences="$occurrences->getCollection()"
                        :offset="($occurrences->currentPage() - 1) * $occurrences->perPage()"
                    />
                </div>

                <div data-pagination>
                    <x-pagination :paginator="$occurrences" :summary="true" />
                </div>

                {{-- Il feed porta con sé i filtri accesi: si sottoscrive
                     esattamente la lista che si sta guardando (§11.10). --}}
                <div class="border-t-2 border-line px-[clamp(1rem,1.8vw,1.625rem)] py-6">
                    <x-feed-links :filters="$filters" />
                </div>
            @else
                {{-- Questo stato vuoto è legittimo: qualcuno ha chiesto qualcosa
                     di preciso e ha diritto a una risposta, che è diverso dal
                     disegnare una sezione vuota in homepage (§8.6). --}}
                <div class="flex flex-col items-start gap-[18px] px-[clamp(1rem,1.8vw,1.625rem)] py-[clamp(2.5rem,6vw,5.625rem)]">
                    <span class="font-display text-[clamp(1.875rem,3.4vw,3.25rem)] leading-none font-extrabold tracking-[-0.04em] uppercase">
                        {{ __('events.empty.search_title') }}
                    </span>

                    <p class="m-0 max-w-[40ch] text-[0.938rem] leading-[1.55] text-ink-muted">
                        {{ __('events.empty.search_body') }}
                    </p>

                    <div class="flex flex-wrap gap-0.5">
                        <a
                            href="{{ route('events.index') }}"
                            class="ui-action inline-flex h-[46px] items-center bg-accent px-[18px] font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
                        >
                            {{ __('events.redirects.to_all') }}
                        </a>

                        <a
                            href="{{ route('events.weekend') }}"
                            class="ui-action inline-flex h-[46px] items-center border-2 border-line px-[18px] font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:border-accent hover:text-accent"
                        >
                            {{ __('events.redirects.to_weekend') }}
                        </a>
                    </div>
                </div>
            @endif
        </section>

        {{-- La colonna dei filtri. Nel documento sta **dopo** i risultati e
             sullo schermo grande torna a sinistra con `col-start-1`: è lo
             scambio spiegato in testa al file.

             L'`id` è il bersaglio del collegamento «Filtra i risultati», e
             l'`aria-label` nomina la regione — senza, la navigazione per
             landmark annunciava un «complementare» e basta, e adesso che non è
             più il primo blocco della pagina saperlo conta di più. --}}
        <aside
            id="filtri"
            aria-label="{{ __('filters.panel_label') }}"
            class="scroll-mt-header flex flex-col gap-6 overflow-y-auto bg-canvas p-[clamp(1rem,1.6vw,1.375rem)] lg:sticky lg:top-header lg:col-start-1 lg:row-start-1 lg:max-h-below-header"
        >
            <x-filter-bar
                :counts="$facetCounts"
                :filters="$filters"
                :categories="$categories"
                :tags="$tags"
                :municipalities="$municipalities"
                :zones="$zones"
                :venues="$venues"
                :total="$occurrences->total()"
            />

            {{-- "Vicino a me" (§11.7): la posizione si chiede qui, con la frase
                 che dice perché, e non all'apertura del sito. --}}
            <x-near-me :filters="$filters" :counts="$facetCounts" :action="route('events.index')" />
        </aside>

        {{-- La terza colonna: gli stessi risultati, visti da sopra. Segue lo
             scorrimento perché serve mentre si guarda l'elenco, non dopo. --}}
        <section class="flex flex-col bg-canvas lg:col-start-3 lg:row-start-1 lg:sticky lg:top-header lg:h-below-header" aria-label="{{ __('map.label') }}">
            <div class="flex items-center justify-between gap-2.5 border-b-2 border-line px-4 py-3.5">
                <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.16em] uppercase">
                    {{ trans_choice('map.pins', count($mapPayload['markers']), ['count' => count($mapPayload['markers'])]) }}
                </span>
            </div>

            <x-events-map
                :city="$city"
                :filters="$filters"
                :payload="$mapPayload"
                :show-legend="false"
                class="min-h-[320px] flex-auto"
                map-class="h-full min-h-[320px] w-full"
            />
        </section>
    </div>
    </div>
</x-layouts.app>
