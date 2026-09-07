@php
    /*
     * L'ordine delle sezioni è quello di §11.2, disposto come il riferimento
     * adottato (D46): un'apertura divisa in due, una fascia di numeri, e poi
     * sezioni numerate «01 —», «02 —» separate da divisori a tutta larghezza.
     *
     * **Niente contenitore centrato.** Il contenuto arriva ai bordi della
     * finestra e si organizza con i divisori: è la ragione per cui qui non
     * compare `max-w-content` e ogni sezione porta il proprio margine
     * `px-gutter`, che è lo stesso del riferimento.
     *
     * Il contesto passato alla card è la finestra di EventOccurrenceQuery da
     * cui le occorrenze arrivano, così che il badge dica la verità senza
     * ricalcolare nulla (§8, D23).
     */
    $formatter = app(\App\Support\DateFormatter::class);

    /*
     * Il riquadro grande dell'apertura: la data in evidenza, oppure una
     * campagna sponsorizzata quando ce n'è una.
     *
     * **La campagna sostituisce l'evidenza, non le si aggiunge.** È lo spazio
     * più visibile del sito, e affiancarne due significherebbe raddoppiare
     * l'apertura per far posto alla pubblicità — cioè cambiare la pagina in
     * funzione di cosa si è venduto. Sostituendola, lo spazio resta uno e chi
     * guarda vede una cosa sola: dichiarata, se è pagata.
     */
    $heroSponsorizzato = ($heroSponsorship ?? null) !== null;

    $heroOccorrenza = $hero ?? null;

    /* La locandina dell'evento in apertura è l'immagine più grande sopra la
       piega, ed è quella da annunciare al browser prima che scopra l'HTML che
       la contiene (§11.11). */
    $heroPoster = $heroOccorrenza === null
        ? null
        : (\App\Support\Poster::imageSet($heroOccorrenza->event)
            ?? new \App\Support\Media\ImageSet(src: asset('images/home-event-fallback.jpg'), width: 1024, height: 768))
            ->withSizes('(min-width: 840px) 50vw, 100vw')
            /* 840px, non 1024: le due colonne dell'apertura si affiancano
               quando ci stanno, cioe' a `2 x 420px`. Fra 840 e 1024 la
               dichiarazione diceva schermo intero mentre l'immagine ne
               occupava meta', e il browser scaricava il doppio del necessario. */
            ->withPreloadMedia('(min-width: 840px)');
            /* La stessa soglia governa anche l'ANNUNCIO: sotto gli 840px le due
               colonne si impilano e la locandina finisce sotto la piega, dove
               l'elemento piu' grande e' testo. Annunciare come urgente qualcosa
               che non si vede e' una dichiarazione falsa, e l'impaginato lo
               diceva gia' («su un telefono la locandina dell'apertura finisce
               sotto la piega e non e' nemmeno in gara») mentre il preload la
               chiedeva lo stesso.

               ONESTA' SULLA MISURA: togliere l'annuncio sul telefono **non
               sposta l'LCP** — verificato, 2256ms prima e dopo, su due banchi
               indipendenti. Cio' che costa e' scaricare l'immagine, non
               annunciarla: bloccandola del tutto si scende a 1653ms. Resta
               perche' e' gratis e perche' Lighthouse simula la rete, e la
               simulazione pesa male le code di priorita' — non perche' abbia
               mostrato un guadagno. Chi cerca i millisecondi qui non li
               trovera': sono nei byte dell'immagine. */

    /* Le sezioni a griglia, nell'ordine in cui compaiono. La numerazione
       «01 —» è progressiva su ciò che si disegna davvero: una sezione vuota
       non si disegna (§8.6) e non deve lasciare un buco nel conteggio. */
    $griglie = collect([
        ['key' => 'tonight', 'title' => __('events.sections.tonight'), 'eyebrow' => __('events.sections.tonight_eyebrow'), 'context' => 'tonight', 'route' => 'events.today'],
        ['key' => 'today', 'title' => __('events.sections.today'), 'eyebrow' => __('events.sections.today_eyebrow'), 'context' => 'today', 'route' => 'events.today'],
        ['key' => 'featured', 'title' => __('events.sections.featured'), 'eyebrow' => __('events.sections.featured_eyebrow'), 'context' => 'upcoming', 'route' => 'events.index'],
        ['key' => 'weekend', 'title' => __('events.sections.weekend'), 'eyebrow' => __('events.sections.weekend_eyebrow'), 'context' => 'upcoming', 'route' => 'events.weekend'],
    ])->filter(fn (array $s): bool => isset($sections[$s['key']]))->values();

    $numero = 0;
@endphp

<x-layouts.app
    :meta="$city ? app(\App\Services\Seo\EditorialContent::class)->meta($city, new \App\DTOs\PageMeta(title: __('seo.home_title', ['city' => $city->name]), heading: $city->name, description: __('ui.header.tagline', ['city' => $city->name]), canonical: url('/'))) : null"
    :title="$city ? __('seo.home_title', ['city' => $city->name]) : null"
    :wide="true"
    :description="$city?->name ? __('ui.header.tagline', ['city' => $city->name]) : null"
    :preload="$heroPoster"
>
    @if ($city)<x-editorial-content :model="$city" />@endif
    <x-slot:head>
        <x-json-ld :data="$structuredData" />

        {{-- La mappa della sezione «vicino a te». Se questo script non arriva,
             quella sezione resta l'elenco a sinistra e un riquadro con la sua
             frase: la pagina non si rompe. --}}
        @vite('resources/js/map.js')
    </x-slot:head>

    {{-- ------------------------------------------------------------------
         Apertura: a sinistra cosa è questo sito e cosa si può fare subito, a
         destra la data più importante in programma. Due colonne su schermo
         largo, una sull'altra sul telefono — `auto-fit` con soglia a 420px lo
         decide senza un punto di rottura scritto a mano.
    ------------------------------------------------------------------- --}}
    <section class="grid border-b-2 border-line [grid-template-columns:repeat(auto-fit,minmax(min(420px,100%),1fr))]">
        <div class="flex flex-col gap-[clamp(1.125rem,1.8vw,1.625rem)] border-line p-[clamp(1.625rem,3.4vw,3.625rem)] lg:border-r-2">
            @if ($city !== null)
                <div class="flex items-center gap-2.5">
                    <span aria-hidden="true" class="size-2 bg-accent blink-dot"></span>
                    <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-ink-muted uppercase">
                        {{ $todayLine }}
                    </span>
                </div>
            @endif

            <h1 class="m-0 font-display text-[clamp(2.875rem,6.4vw,6.5rem)] leading-[0.9] font-extrabold tracking-[-0.05em] uppercase reveal-clip">
                {{-- `nl2br` perché i ritorni a capo del titolo sono parte del
                     disegno: nel riferimento sta su tre righe, e in HTML un «a
                     capo» nel testo non è un «a capo» a schermo. I due valori
                     inseriti passano da `e()`; il testo attorno è nostro. --}}
                {!! nl2br(__('ui.hero.title', ['city' => e($city?->name ?? config('app.name')), 'accent' => '<span class="text-accent">'.e(__('ui.hero.title_accent')).'</span>'])) !!}
            </h1>

            <p class="m-0 max-w-[52ch] text-[clamp(0.938rem,1.15vw,1.063rem)] leading-[1.55] text-pretty text-ink-muted">
                {{ __('ui.hero.lead') }}
            </p>

            {{-- I ritagli rapidi. Nel riferimento sono la prima cosa che si
                 tocca dopo il titolo: non sono voci di menu, sono l'elenco di
                 sempre guardato da quattro finestre diverse. --}}
            @if ($quickFilters !== [])
                <div class="flex w-fit flex-wrap gap-0.5 bg-line p-0.5">
                    @foreach ($quickFilters as $filtro)
                        <a
                            href="{{ $filtro['url'] }}"
                            class="flex items-baseline gap-2 bg-canvas px-3.5 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:bg-accent hover:text-on-accent"
                        >
                            {{ $filtro['label'] }}
                            <span class="text-[0.625rem] text-ink-subtle">{{ $filtro['count'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-2.5">
                <a
                    href="{{ route('events.index') }}"
                    class="inline-flex h-[52px] items-center gap-2 bg-accent px-[22px] font-display text-xs leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
                >
                    {{ trans_choice('ui.hero.explore', $stats['upcoming'], ['count' => $stats['upcoming']]) }}
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="square"><path d="M5 12h14M13 5l7 7-7 7"></path></svg>
                </a>

                @if (\Illuminate\Support\Facades\Route::has('map.index'))
                    <a
                        href="{{ route('map.index') }}"
                        class="inline-flex h-[52px] items-center gap-2 border-2 border-ink px-[22px] font-display text-xs leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:bg-ink hover:text-ink-inverted"
                    >
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square"><path d="M12 21s-7-6.1-7-11a7 7 0 1 1 14 0c0 4.9-7 11-7 11z"></path><circle cx="12" cy="10" r="2.4"></circle></svg>
                        {{ __('ui.hero.open_map') }}
                    </a>
                @endif
            </div>
        </div>

        {{-- La data in evidenza: l'unica fotografia sopra la piega. La
             fotografia sta sotto un velo che scurisce verso il basso, perché
             il testo ci sta sopra e deve restare leggibile su qualunque
             immagine il locale abbia caricato. --}}
        @if ($heroOccorrenza !== null)
            @php
                $heroEvento = $heroOccorrenza->event;
                $heroLuogo = $heroEvento->venue;
                $heroCapienza = \App\Support\Capacity::for($heroOccorrenza);
            @endphp

            <a
                href="{{ route('events.show', $heroEvento) }}"
                @if ($heroSponsorizzato) rel="sponsored" @endif
                class="group relative flex min-h-[clamp(26.25rem,46vw,38.75rem)] flex-col overflow-hidden"
                data-home-hero
                @if ($heroSponsorizzato)
                    data-sponsorship="{{ $heroSponsorship->getKey() }}"
                    data-sponsorship-impression="{{ route('sponsorships.metric', ['sponsorship' => $heroSponsorship, 'metric' => 'impressions']) }}"
                    data-sponsorship-click="{{ route('sponsorships.metric', ['sponsorship' => $heroSponsorship, 'metric' => 'clicks']) }}"
                @endif
            >
                @if ($heroPoster !== null)
                    <x-media-image
                        :set="$heroPoster"
                        alt=""
                        :width="$heroPoster->width ?? 1200"
                        :height="$heroPoster->height ?? 1200"
                        :eager="true"
                        class="absolute inset-0 size-full object-cover opacity-[0.68] grayscale-photo transition-transform duration-700 ease-out-soft group-hover:scale-[1.03]"
                    />
                @endif

                <span aria-hidden="true" class="absolute inset-0 bg-[linear-gradient(180deg,rgba(11,11,11,.25)_0%,rgba(11,11,11,.55)_46%,rgba(11,11,11,.94)_100%)]"></span>

                <div class="relative flex items-start justify-between gap-3 p-[clamp(1.25rem,2.2vw,2.125rem)]">
                    {{-- L'etichetta occupa lo stesso posto in entrambi i casi:
                         è la parola a cambiare, non il rilievo. Una pubblicità
                         che si annuncia in un angolo più discreto
                         dell'evidenza redazionale non si annuncia. --}}
                    <span class="flex flex-wrap items-baseline gap-x-2 bg-accent px-2.5 py-[7px] font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-on-accent uppercase">
                        @if ($heroSponsorizzato)
                            {{ __('sponsorships.label') }}
                            <span class="font-normal tracking-[0.1em] normal-case opacity-80">
                                {{ __('sponsorships.by', ['advertiser' => $heroSponsorship->advertiser_name]) }}
                            </span>
                        @else
                            {{ __('events.sections.today') }}
                        @endif
                    </span>

                    @if ($heroCapienza !== null && $heroCapienza->percentSold() !== null)
                        <span class="border-2 border-ink px-2.5 py-1.5 font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] uppercase animate-[floatY_3.4s_ease-in-out_infinite] [animation-play-state:var(--anim-play)]">
                            {{ __('events.capacity.sold', ['percent' => $heroCapienza->percentSold()]) }}
                        </span>
                    @endif
                </div>

                <div class="relative mt-auto flex flex-col gap-3.5 p-[clamp(1.25rem,2.2vw,2.125rem)]">
                    <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.16em] text-accent uppercase">
                        {{ collect([$heroEvento->category?->name, $formatter->dayAndTime($heroOccorrenza->business_date, $heroOccorrenza->starts_at)])->filter()->implode(' '.__('common.separator').' ') }}
                    </span>

                    <h2 class="m-0 font-display text-[clamp(1.875rem,3.3vw,3.5rem)] leading-[0.94] font-extrabold tracking-[-0.04em] uppercase">
                        {{ $heroEvento->title }}
                    </h2>

                    @if ($heroCapienza !== null && $heroCapienza->percentSold() !== null)
                        <div class="flex flex-col gap-[7px]">
                            <div class="h-1 overflow-hidden bg-ink/20">
                                <div class="h-full origin-left bg-accent animate-[lineGrow_1.2s_cubic-bezier(.2,.8,.2,1)_both]" style="width: {{ $heroCapienza->percentSold() }}%"></div>
                            </div>
                            <div class="flex justify-between gap-3 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase">
                                <span>{{ collect([$heroLuogo?->name, $heroLuogo?->zone ?: $heroLuogo?->municipality])->filter()->implode(' '.__('common.separator').' ') }}</span>
                                <span class="text-accent">{{ trans_choice('events.capacity.left', $heroCapienza->left, ['count' => $heroCapienza->left]) }}</span>
                            </div>
                        </div>
                    @elseif ($heroLuogo !== null)
                        <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase">
                            {{ collect([$heroLuogo->name, $heroLuogo->zone ?: $heroLuogo->municipality])->filter()->implode(' '.__('common.separator').' ') }}
                        </span>
                    @endif

                    <div class="flex items-center justify-between gap-3 border-t-2 border-line pt-3.5">
                        <span class="font-display text-[1.375rem] leading-none font-extrabold tracking-[-0.02em]">
                            <x-price-tag :event="$heroEvento" />
                        </span>
                        <span class="inline-flex items-center gap-2 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-accent uppercase">
                            {{ __('events.actions.view') }}
                            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="square"><path d="M5 12h14M13 5l7 7-7 7"></path></svg>
                        </span>
                    </div>
                </div>
            </a>
        @endif
    </section>

    {{-- ------------------------------------------------------------------
         La fascia dei numeri: dice se il catalogo regge, prima ancora di
         scorrere. Sono le misure di §1 del piano.
    ------------------------------------------------------------------- --}}
    <section class="grid gap-0.5 border-b-2 border-line bg-line [grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr))]" aria-label="{{ __('ui.stats.label') }}">
        @foreach ($statCells as $cella)
            <div class="flex flex-col gap-1.5 bg-canvas px-[clamp(1rem,2vw,1.75rem)] py-[22px] reveal-up">
                <span class="font-display text-[clamp(1.75rem,2.6vw,2.75rem)] leading-none font-extrabold tracking-[-0.03em] {{ $cella['accent'] ?? false ? 'text-accent' : '' }}">
                    {{ $cella['value'] }}
                </span>
                <span class="font-display text-[0.594rem] leading-[1.3] font-extrabold tracking-[0.16em] text-ink-subtle uppercase">
                    {{ $cella['label'] }}
                </span>
            </div>
        @endforeach
    </section>

    {{-- "In corso adesso" e "Inizia tra poco": disegnate dal server, non dopo
         il primo disegno. §12.3 le voleva differite per non mettere in cache
         una sezione che cambia ogni minuto; la misura dice altro, e differirle
         spostava di 2,7 secondi il momento in cui compare la prima immagine
         grande (D40). Era un componente Livewire: bastava la sua presenza a
         far entrare 84 KB di JavaScript nella pagina, e su una pagina servita
         dalla cache quello script non veniva iniettato affatto — il frammento
         non arrivava mai e le due sezioni non si vedevano (D51). --}}
    <x-live-now />

    {{-- La campagna della pagina iniziale, subito dopo la fascia dei numeri:
         sopra la piega non ci va — quello spazio è la data più importante in
         programma, e venderlo cambierebbe cosa il sito dice di sé. --}}
    @if ($cardSponsorship !== null)
        <x-sponsored-card
            :sponsorship="$cardSponsorship"
            class="border-b-2 border-line"
            level="h2"
        />
    @endif

    @foreach ($griglie as $sezione)
        @php $numero++; @endphp

        <section class="border-b-2 border-line defer-offscreen" aria-labelledby="sezione-{{ $sezione['key'] }}">
            <div class="flex flex-wrap items-end justify-between gap-5 px-gutter pt-[clamp(1.5rem,2.8vw,2.75rem)] pb-[clamp(1.125rem,2vw,1.625rem)]">
                <div class="flex flex-col gap-2">
                    <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-accent uppercase">
                        {{ str_pad((string) $numero, 2, '0', STR_PAD_LEFT) }} {{ __('common.dash') }} {{ $sezione['eyebrow'] }}
                    </span>
                    <h2 id="sezione-{{ $sezione['key'] }}" class="m-0 font-display text-[clamp(1.875rem,4vw,4rem)] leading-[0.94] font-extrabold tracking-[-0.04em] uppercase reveal-left">
                        {{ $sezione['title'] }}
                    </h2>
                </div>

                <a
                    href="{{ route($sezione['route']) }}"
                    class="inline-flex items-center gap-2 border-b-2 border-accent pb-[5px] font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:text-accent"
                >
                    {{ trans_choice('events.sections.see_all', $sections[$sezione['key']]->count(), ['count' => $sections[$sezione['key']]->count()]) }}
                    <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="square"><path d="M5 12h14M13 5l7 7-7 7"></path></svg>
                </a>
            </div>

            <x-event-grid
                :occurrences="$sections[$sezione['key']]"
                :context="$sezione['context']"
                class="border-t-2 border-line"
            />
        </section>
    @endforeach

    @if ($categories !== [])
        @php $numero++; @endphp

        <section class="border-b-2 border-line defer-offscreen" aria-labelledby="sezione-categorie">
            <div class="flex flex-col gap-2 px-gutter pt-[clamp(1.5rem,2.8vw,2.75rem)] pb-[clamp(1.125rem,2vw,1.625rem)]">
                <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-accent uppercase">
                    {{ str_pad((string) $numero, 2, '0', STR_PAD_LEFT) }} {{ __('common.dash') }} {{ __('events.sections.by_category_eyebrow') }}
                </span>
                <h2 id="sezione-categorie" class="m-0 font-display text-[clamp(1.875rem,4vw,4rem)] leading-[0.94] font-extrabold tracking-[-0.04em] uppercase reveal-left">
                    {{ __('events.sections.by_category') }}
                </h2>
            </div>

            <x-category-grid :categories="$categories" class="border-t-2 border-line" />
        </section>
    @endif

    {{-- ------------------------------------------------------------------
         Vicino a te: l'elenco a sinistra e la mappa a destra, che si guardano.
         La posizione non si chiede all'apertura (§11.7): l'elenco è ordinato
         per centro città finché qualcuno non tocca il pulsante di
         localizzazione dentro la mappa.
    ------------------------------------------------------------------- --}}
    @if ($nearby->isNotEmpty() && \Illuminate\Support\Facades\Route::has('map.index'))
        @php $numero++; @endphp

        <section class="border-b-2 border-line defer-offscreen" aria-labelledby="sezione-vicino">
            <div class="flex flex-wrap items-end justify-between gap-4 px-gutter pt-[clamp(1.5rem,2.8vw,2.75rem)] pb-[clamp(1.125rem,2vw,1.625rem)]">
                <div class="flex flex-col gap-2">
                    <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-accent uppercase">
                        {{ str_pad((string) $numero, 2, '0', STR_PAD_LEFT) }} {{ __('common.dash') }} {{ __('events.sections.nearby_eyebrow') }}
                    </span>
                    <h2 id="sezione-vicino" class="m-0 font-display text-[clamp(1.875rem,4vw,4rem)] leading-[0.94] font-extrabold tracking-[-0.04em] uppercase">
                        {{ __('events.sections.nearby') }}
                    </h2>
                </div>
                <p class="m-0 max-w-[38ch] text-[0.813rem] leading-[1.5] text-ink-muted">
                    {{ __('events.sections.nearby_lead', ['city' => $city?->name ?? '']) }}
                </p>
            </div>

            <div class="grid gap-0.5 border-t-2 border-line bg-line [grid-template-columns:repeat(auto-fit,minmax(min(340px,100%),1fr))]">
                <div class="flex flex-col bg-canvas">
                    @foreach ($nearby as $occorrenza)
                        <a
                            href="{{ route('events.show', $occorrenza->event) }}"
                            class="group flex items-center gap-3.5 border-b-2 border-line px-[clamp(1rem,1.6vw,1.25rem)] py-3.5 transition-colors hover:bg-accent/[0.055]"
                        >
                            <span class="min-w-14 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.1em] text-accent uppercase">
                                {{ $occorrenza->is_all_day ? __('events.badge.all_day') : $formatter->time($occorrenza->starts_at) }}
                            </span>
                            <span class="flex min-w-0 flex-auto flex-col gap-1">
                                <span class="font-display text-[clamp(0.938rem,1.25vw,1.188rem)] leading-[1.1] font-extrabold tracking-[-0.02em] uppercase">{{ $occorrenza->event->title }}</span>
                                <span class="truncate text-xs leading-[1.4] text-ink-subtle">
                                    {{ collect([$occorrenza->event->venue?->name, $occorrenza->event->venue?->zone ?: $occorrenza->event->venue?->municipality])->filter()->implode(' '.__('common.separator').' ') }}
                                </span>
                            </span>
                            <span class="font-display text-[0.813rem] leading-none font-extrabold whitespace-nowrap">
                                <x-price-tag :event="$occorrenza->event" />
                            </span>
                        </a>
                    @endforeach

                    <div class="mt-auto p-[18px]">
                        <a
                            href="{{ route('map.index') }}"
                            class="flex h-[46px] w-full items-center bg-accent px-4 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
                        >
                            {{ __('ui.hero.open_map_full') }}
                        </a>
                    </div>
                </div>

                <div class="relative min-h-[clamp(25rem,44vw,35rem)] bg-canvas">
                    <x-events-map
                        :city="$city"
                        :filters="$mapFilters"
                        :payload="$mapPayload"
                        :show-legend="false"
                        class="absolute inset-0"
                        map-class="h-full w-full"
                    />
                </div>
            </div>
        </section>
    @endif

    {{-- ------------------------------------------------------------------
         La chiusura, in negativo: fondo lime, testo nero. È l'unico blocco
         pieno di colore della pagina, e serve a questo — chiudere.
    ------------------------------------------------------------------- --}}
    <section class="grid items-end gap-[clamp(1.5rem,3vw,3.25rem)] bg-accent px-gutter py-[clamp(2.125rem,5vw,5.375rem)] text-on-accent [grid-template-columns:repeat(auto-fit,minmax(min(360px,100%),1fr))]" aria-label="{{ __('ui.cta.label') }}">
        <h2 class="m-0 font-display text-[clamp(2.125rem,5.2vw,5.25rem)] leading-[0.9] font-extrabold tracking-[-0.045em] text-on-accent uppercase">
            {!! nl2br(e(__('ui.cta.title'))) !!}
        </h2>

        <div class="flex flex-col gap-4">
            <p class="m-0 max-w-[44ch] text-[clamp(0.938rem,1.2vw,1.125rem)] leading-[1.5] text-on-accent/80">
                {{ __('ui.cta.lead') }}
            </p>

            <div class="flex flex-wrap gap-0.5">
                <a
                    href="{{ route('submissions.create') }}"
                    class="inline-flex h-[54px] items-center bg-canvas px-6 font-display text-xs leading-none font-extrabold tracking-[0.14em] text-accent uppercase transition-colors hover:bg-ink hover:text-ink-inverted"
                >
                    {{ __('events.submit.title') }}
                </a>
                <a
                    href="{{ route('venue-applications.create') }}"
                    class="inline-flex h-[54px] items-center bg-canvas px-6 font-display text-xs leading-none font-extrabold tracking-[0.14em] text-accent uppercase transition-colors hover:bg-ink hover:text-ink-inverted"
                >
                    {{ __('venues.claim.title') }}
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
