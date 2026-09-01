{{--
    Scheletro del sito pubblico. Mobile-first (§11.11).

    Il nome del prodotto arriva sempre da config('app.name') e non compare mai
    scritto a mano: cambiarlo deve costare una riga di .env (D10).

    I percorsi della navigazione sono quelli fissati da §11.1. Sono struttura,
    non testo: le etichette stanno tutte in lang/it/ui.php.
--}}
@php
    $app = config('app.name');
    $currentCity = app(\App\Support\CurrentCity::class);
    $city = $currentCity->get();
    $formatter = app(\App\Support\DateFormatter::class);
    $today = $formatter->weekdayDate(\Carbon\CarbonImmutable::now($currentCity->timezone()));

    /*
     * La navigazione si costruisce dalle rotte che esistono davvero: una voce
     * la cui rotta non è ancora registrata semplicemente non compare, invece
     * di portare a un 404. Quando la mappa e il calendario arriveranno,
     * torneranno nel menu senza che nessuno debba ricordarsene.
     */
    $links = function (array $items): array {
        $available = [];

        foreach ($items as $name => $label) {
            if (\Illuminate\Support\Facades\Route::has($name)) {
                $available[] = ['name' => $name, 'url' => route($name), 'label' => $label];
            }
        }

        return $available;
    };

    $navigation = $links([
        'events.today' => __('ui.nav.today'),
        'events.tomorrow' => __('ui.nav.tomorrow'),
        'events.weekend' => __('ui.nav.weekend'),
        'events.free' => __('ui.nav.free'),
        'map.index' => __('ui.nav.map'),
        'calendar.index' => __('ui.nav.calendar'),
        'venues.index' => __('ui.nav.venues'),
    ]);

    /*
     * La navigazione della testata e' corta di proposito: Eventi, Mappa,
     * Locali, Calendario. «Oggi», «domani», «weekend» e «gratis» non sono
     * luoghi del sito, sono ritagli dello stesso elenco — nel riferimento
     * stanno fra i pulsanti rapidi della prima schermata e fra i filtri della
     * lista, dove chi guarda i risultati puo' cambiarli senza tornare su.
     */
    $primaryNav = $links([
        'events.index' => __('ui.nav.events'),
        'map.index' => __('ui.nav.map'),
        'venues.index' => __('ui.nav.venues'),
        'calendar.index' => __('ui.nav.calendar'),
    ]);

    /*
     * Quante date ha salvato chi guarda. Da autenticati e' un conteggio vero;
     * da anonimi i salvataggi vivono nel browser (§15.1) e il numero lo
     * riempie lo script — il server non li conosce e non deve conoscerli.
     */
    $savedCount = auth()->check() ? auth()->user()->savedOccurrences()->count() : 0;

    $discoverLinks = $links([
        'events.today' => __('ui.nav.today'),
        'events.weekend' => __('ui.nav.weekend'),
        'events.free' => __('ui.nav.free'),
        'map.index' => __('ui.nav.map'),
        'calendar.index' => __('ui.nav.calendar'),
        'venues.index' => __('ui.nav.venues'),
    ]);

    /*
     * I feed (§11.10). Sono una leva di crescita, non un dettaglio da nascondere
     * in fondo: chi sottoscrive il calendario della città se lo ritrova nel
     * telefono ogni mattina senza dover tornare qui.
     */
    $feedLinks = $links([
        'feeds.calendar' => __('ui.footer.calendar_feed'),
        'feeds.rss' => __('ui.footer.rss_feed'),
    ]);

    $venueLinks = $links([
        'venue-applications.create' => __('ui.footer.register_venue'),
        'submissions.create' => __('ui.footer.submit_event'),
        'filament.venue.auth.login' => __('ui.footer.venue_login'),
    ]);

    /*
     * Le pagine informative del piè di pagina (§11.1, §16). L'elenco non è
     * scritto qui: sono le pagine **pubblicate** che esistono davvero, nel loro
     * ordine di redazione. Un collegamento a un'informativa privacy che nessuno
     * ha ancora scritto sarebbe un 404 nel punto in cui un'autorità va a
     * guardare per prima.
     */
    $legalLinks = \Illuminate\Support\Facades\Route::has('pages.show')
        ? \App\Models\Page::query()
            ->published()
            ->ordered()
            ->get(['slug', 'title'])
            ->map(fn (\App\Models\Page $page): array => [
                'url' => route('pages.show', ['slug' => $page->slug]),
                'label' => $page->title,
            ])
            ->all()
        : [];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Un tema solo, scuro (D46). Dichiararlo qui fa nascere scure anche le
         parti che disegna il browser — barre di scorrimento, controlli dei
         moduli, la finestra di scelta di una data — invece di vederle
         comparire bianche in mezzo alla pagina. --}}
    <meta name="color-scheme" content="dark">

    {{-- Il token con cui lo script conferma al server i salvataggi fatti dal
         cuore: senza, ogni chiamata asincrona sarebbe un 419. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ filled($title ?? null) ? $title.' — '.$app : $app }}</title>

    @if (filled($description ?? null))
        <meta name="description" content="{{ $description }}">
    @endif

    @if (filled($canonical ?? null))
        <link rel="canonical" href="{{ $canonical }}">
    @endif

    @if (filled($robots ?? null))
        <meta name="robots" content="{{ $robots }}">
    @endif

    {{-- `hreflang` predisposto (§12.2). Oggi il sito parla una lingua sola e
         l'unica riga utile è `x-default`, che dice «questa è la versione da
         servire a chi non rientra in nessuna delle altre». Il giorno in cui
         `lang/en` smetterà di essere vuoto basterà aggiungere la lingua in
         config/seo.php e ogni pagina dichiarerà la propria alternativa. --}}
    <link rel="alternate" hreflang="x-default" href="{{ $canonical ?? url()->current() }}">
    @foreach (config('seo.locales') as $locale => $prefix)
        <link
            rel="alternate"
            hreflang="{{ $locale }}"
            href="{{ $prefix === null ? ($canonical ?? url()->current()) : url($prefix.'/'.ltrim(request()->path(), '/')) }}"
        >
    @endforeach

    {{-- Anteprima nei social e nelle applicazioni di messaggistica: senza,
         un evento condiviso arriva come un link nudo (§12.2). --}}
    <meta property="og:site_name" content="{{ $app }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">
    <meta property="og:title" content="{{ filled($title ?? null) ? $title : $app }}">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    <meta name="twitter:card" content="{{ filled($image ?? null) ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ filled($title ?? null) ? $title : $app }}">

    @if (filled(config('seo.twitter_site')))
        <meta name="twitter:site" content="{{ config('seo.twitter_site') }}">
    @endif

    @if (filled($description ?? null))
        <meta property="og:description" content="{{ $description }}">
        <meta name="twitter:description" content="{{ $description }}">
    @endif

    @if (filled($image ?? null))
        <meta property="og:image" content="{{ $image }}">
        <meta name="twitter:image" content="{{ $image }}">

        {{-- Le misure dell'anteprima permettono a chi riceve il collegamento di
             riservare il rettangolo prima di averla scaricata: senza, la scheda
             nella conversazione compare come testo e poi salta. --}}
        @if (filled($imageWidth ?? null) && filled($imageHeight ?? null))
            <meta property="og:image:width" content="{{ $imageWidth }}">
            <meta property="og:image:height" content="{{ $imageHeight }}">
        @endif
    @endif

    {{-- Il carattere, chiesto prima che il foglio di stile lo nomini.

         Archivo arriva da `@fontsource-variable`, importato dentro `app.css`:
         il browser scarica l'HTML, poi il CSS, e solo dopo averlo letto scopre
         che gli serve un `.woff2`. Tre viaggi in fila, e nel frattempo i
         titoli — enormi e in grassetto 800 — restano nel carattere di
         ripiego. Sono l'elemento più grande sopra la piega, quindi finché non
         arriva il font il tempo di disegno non si ferma.

         **Il `crossorigin` non è facoltativo**, nemmeno per un file del nostro
         stesso dominio: i font si scaricano sempre in modalità anonima, e un
         preload senza quell'attributo finisce in una cache diversa da quella
         dove il CSS andrà a cercarlo — il file si scarica due volte e il
         preload fa perdere tempo invece di guadagnarlo. --}}
    @php
        /* Forma estesa, non `@php(...)`: quella compatta qui si compilava in un
           `<?php` senza chiusura, e da lì in giù il resto dell'intestazione
           finiva dentro PHP grezzo — il blocco che definisce `$lcp`, tre righe
           più sotto, non veniva mai eseguito e la pagina si spegneva
           lamentandosi di una variabile che nel sorgente c'era. */
        $fontLatino = \App\Support\Fonts::latin();
    @endphp
    @if ($fontLatino !== null)
        <link rel="preload" as="font" type="font/woff2" href="{{ $fontLatino }}" crossorigin>
    @endif

    {{-- Preload dell'immagine più grande sopra la piega (§11.11).

         Se ne dichiara **una sola**, nel formato migliore disponibile: `type`
         fa sì che chi non apre quel formato ignori la riga invece di scaricare
         un file che non userà, e per quei browser resta comunque
         `fetchpriority="high"` sull'immagine, che è già nell'HTML iniziale e
         quindi scopribile subito. Dichiararne due, una per formato, farebbe
         scaricare entrambi i file a chi li apre tutti e due: il preload
         smetterebbe di far guadagnare tempo e inizierebbe a farne perdere. --}}
    @php
        $lcp = $preload ?? null;

        /*
         * Il formato migliore fra quelli DAVVERO disponibili. L'AVIF quando
         * c'è, il WebP altrimenti: prima si annunciava solo l'AVIF, e dove
         * quella variante non esiste — una macchina senza il delegato libheif
         * — si ricadeva sul preload dell'originale, senza `imagesrcset`. Il
         * risultato era scaricare in anticipo l'immagine grande anche su un
         * telefono: un preload che fa perdere tempo invece di guadagnarlo.
         */
        $lcpFormato = collect(['image/avif', 'image/webp'])
            ->first(fn (string $mime): bool => ($lcp->sources[$mime] ?? null) !== null);
    @endphp
    @if ($lcp !== null && $lcpFormato !== null)
        <link
            rel="preload"
            as="image"
            type="{{ $lcpFormato }}"
            imagesrcset="{{ $lcp->sources[$lcpFormato] }}"
            imagesizes="{{ $lcp->sizes ?? '100vw' }}"
            fetchpriority="high"
        >
    @elseif ($lcp !== null)
        <link rel="preload" as="image" href="{{ $lcp->src }}" fetchpriority="high">
    @endif

    {{-- I feed dichiarati qui sono ciò che fa comparire il pulsante "sottoscrivi"
         nei lettori di feed e in certi browser: senza, l'indirizzo esiste ma
         non lo trova nessuno (§11.10). --}}
    @if (\Illuminate\Support\Facades\Route::has('feeds.rss'))
        <link rel="alternate" type="application/rss+xml" title="{{ $app }}" href="{{ route('feeds.rss') }}">
    @endif

    @if (\Illuminate\Support\Facades\Route::has('feeds.calendar'))
        <link rel="alternate" type="text/calendar" title="{{ $app }}" href="{{ route('feeds.calendar') }}">
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- L'analitica senza cookie (§16). Con `ANALYTICS_*` vuote non emette
         niente: nessuno script, nessuna richiesta verso terzi. --}}
    <x-analytics />

    {{ $head ?? '' }}
</head>
<body class="min-h-dvh bg-canvas text-ink antialiased">
    <a
        href="#contenuto"
        class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:focus:bg-brand focus:px-4 focus:py-2 focus:font-semibold focus:text-on-brand"
    >
        {{ __('ui.skip_to_content') }}
    </a>

    <x-impersonation-banner />

    {{-- Ciò che lo script deve sapere sull'area personale (§15.1): se c'è una
         sessione, dopo quanti salvataggi offrire il promemoria e dove mandare
         le date salvate da anonimo. Sta nel documento e non nello script
         perché sono valori del server, non costanti del browser. --}}
    <div
        hidden
        data-account
        data-account-authenticated="{{ auth()->check() ? '1' : '0' }}"
        data-account-prompt-after="{{ config('account.guest_save_prompt_after') }}"
        @auth data-account-merge="{{ route('account.saved.merge') }}" @endauth
    ></div>

    {{--
        Testata fissa (D46). Due fasce: la barra di navigazione alta 70px e il
        nastro scorrevole alto 32px, per 102px complessivi — da cui il
        `pt-header` che ogni pagina applica al proprio contenuto.

        Non c'e' contenitore centrato: il riferimento porta il contenuto fino
        ai bordi della finestra e organizza la pagina con divisori da 2px, che
        e' il motivo per cui questi bordi non sono decorazione e non vanno
        assottigliati.
    --}}
    <header class="fixed inset-x-0 top-0 z-[9000] border-b-2 border-line bg-[rgba(11,11,11,.94)] backdrop-blur-2xl">
        <div class="flex h-[70px] items-center gap-[clamp(0.75rem,2vw,1.875rem)] px-[clamp(0.875rem,2.2vw,1.875rem)]">
            <a href="{{ url('/') }}" class="flex shrink-0 items-baseline gap-1.5" aria-label="{{ __('ui.header.home', ['app' => $app]) }}">
                <span class="font-display text-[1.625rem] leading-none font-extrabold tracking-[-0.05em] text-ink">{{ $app }}</span>
                @if ($city !== null)
                    <span class="font-display text-[0.594rem] leading-none font-extrabold tracking-[0.2em] text-accent uppercase">{{ $city->name }}</span>
                @endif
            </a>

            <nav aria-label="{{ __('ui.nav.label') }}" class="hidden items-stretch lg:flex">
                @foreach ($primaryNav as $item)
                    @php $isCurrent = request()->routeIs($item['name']); @endphp
                    <a
                        href="{{ $item['url'] }}"
                        @if ($isCurrent) aria-current="page" @endif
                        class="relative mr-4 px-0.5 py-2 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] whitespace-nowrap uppercase transition-colors hover:text-accent {{ $isCurrent ? 'text-ink' : 'text-ink-muted' }}"
                    >
                        {{ $item['label'] }}
                        @if ($isCurrent)
                            <span aria-hidden="true" class="absolute inset-x-0 bottom-0 h-0.5 bg-accent"></span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <form
                action="{{ route('search') }}"
                method="GET"
                role="search"
                class="flex h-[38px] max-w-[420px] flex-auto items-center border-2 border-line pl-2.5 focus-within:border-accent"
            >
                <label for="site-search" class="sr-only">
                    {{ __('ui.header.search_label', ['city' => $city?->name ?? $app]) }}
                </label>
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square" class="shrink-0 text-ink-subtle">
                    <circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path>
                </svg>
                <input
                    id="site-search"
                    type="search"
                    name="q"
                    value="{{ request()->string('q') }}"
                    placeholder="{{ __('ui.header.search_placeholder') }}"
                    class="h-full min-w-0 flex-auto border-0 bg-transparent px-2.5 text-[0.813rem] text-ink placeholder:text-ink-subtle focus:outline-none"
                >
                <button type="submit" class="h-full shrink-0 bg-accent px-3.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase">
                    {{ __('common.actions.search') }}
                </button>
            </form>

            <a
                href="{{ auth()->check() ? route('account.saved') : route('login') }}"
                class="ml-auto hidden h-[38px] shrink-0 items-center gap-2 border-2 border-line px-3 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:border-accent hover:text-accent sm:flex"
            >
                <svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square">
                    <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path>
                </svg>
                {{ __('account.nav.saved') }}
                <span class="text-accent" data-saved-count>{{ $savedCount }}</span>
            </a>

            @if (\Illuminate\Support\Facades\Route::has('submissions.create'))
                <a
                    href="{{ route('submissions.create') }}"
                    class="hidden h-[38px] shrink-0 items-center bg-accent px-3.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] whitespace-nowrap text-on-accent uppercase transition-colors hover:bg-brand-strong md:inline-flex"
                >
                    {{ __('ui.header.submit_event') }}
                </a>
            @endif
        </div>

        {{-- Il nastro: le stesse notizie che stanno nella pagina, in movimento.
             Non e' un ornamento — dice quante date ci sono stasera e quali
             stanno per finire, che e' l'informazione per cui si apre il sito.
             Il duplicato serve allo scorrimento continuo: la striscia trasla
             del 50% e il secondo blocco prende il posto del primo senza
             stacchi. `aria-hidden` sul duplicato evita che uno screen reader
             legga tutto due volte. --}}
        <x-ticker />
    </header>

    {{--
        Il contenuto parte sotto la testata fissa (70px + 32px di nastro).

        **Il contenitore centrato c'è per difetto e si toglie a richiesta.**
        Le pagine che arrivano ai bordi — la prima schermata, la lista con la
        sua mappa, la mappa a schermo intero — sono tre; tutte le altre sono
        moduli, testi e pagine dell'area personale, che senza un contenitore
        finiscono appiccicate al bordo sinistro dello schermo. Il difetto
        inverso l'ho già fatto: togliendo il contenitore dal layout per le tre
        pagine larghe, i moduli di «registra il tuo locale» e «proponi un
        evento» sono rimasti fuori squadra.
    --}}
    <main id="contenuto" class="pt-header">
        @if (! ($wide ?? false))
            {{-- `narrow` per moduli e testi: una riga da 1200 px non si legge
                 e un campo largo 1200 px non si compila. Sotto i 48rem il
                 contenuto resta centrato davvero, invece di stare a sinistra
                 dentro un contenitore molto più largo di lui. --}}
            {{-- Le pagine strette respirano di più sopra il titolo: sono
                 moduli e testi, dove il titolo è la prima cosa che si legge e
                 attaccarlo al nastro della testata lo fa sembrare parte di
                 quello. Le pagine larghe hanno le proprie sezioni con i propri
                 margini e non ne hanno bisogno. --}}
            <div @class([
                'mx-auto w-full px-gutter',
                'max-w-3xl pt-[clamp(2.5rem,6vw,5rem)] pb-16' => $narrow ?? false,
                'max-w-content py-8' => ! ($narrow ?? false),
            ])>
        @endif
        {{-- Conferma dell'ultima azione (una proposta inviata, una segnalazione
             ricevuta): sta nel layout perché è l'unico punto che ogni pagina
             attraversa dopo un reindirizzamento. --}}
        @if (session()->has('status'))
            <p role="status" class="flex items-center gap-2.5 border-b-2 border-line bg-accent px-gutter py-3.5 font-display text-[0.688rem] font-extrabold tracking-[0.14em] text-on-accent uppercase">
                <span aria-hidden="true" class="size-[7px] bg-on-accent"></span>
                {{ session('status') }}
            </p>
        @endif

        {{ $slot }}

        @if (! ($wide ?? false))
            </div>
        @endif
    </main>

    <x-save-prompt />

    {{-- Il consenso (§16). Sta in fondo al documento e non copre la pagina:
         chi vuole leggere prima di decidere, può. --}}
    <x-cookie-banner />

    <footer class="mt-section border-t border-line bg-canvas-deep" aria-label="{{ __('ui.footer.label') }}">
        <div class="mx-auto grid w-full max-w-content gap-8 px-gutter py-10 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <p class="font-display text-lg font-bold tracking-tight text-ink">{{ $app }}</p>
                <p class="mt-2 text-sm text-ink-muted">
                    {{ __('ui.footer.about_body', ['app' => $app, 'city' => $city?->name ?? '']) }}
                </p>
            </div>

            @if ($discoverLinks !== [])
                <nav aria-labelledby="footer-discover">
                    <h2 id="footer-discover" class="text-eyebrow text-ink-subtle uppercase">{{ __('ui.footer.discover_title') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($discoverLinks as $link)
                            <li><a class="text-ink-muted hover:text-ink" href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            @if ($venueLinks !== [])
                <nav aria-labelledby="footer-venues">
                    <h2 id="footer-venues" class="text-eyebrow text-ink-subtle uppercase">{{ __('ui.footer.venues_title') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($venueLinks as $link)
                            <li><a class="text-ink-muted hover:text-ink" href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            @if ($feedLinks !== [])
                <nav aria-labelledby="footer-feeds">
                    <h2 id="footer-feeds" class="text-eyebrow text-ink-subtle uppercase">{{ __('ui.footer.feeds_title') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($feedLinks as $link)
                            <li><a class="text-ink-muted hover:text-ink" href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            @if ($legalLinks !== [])
                <nav aria-labelledby="footer-legal">
                    <h2 id="footer-legal" class="text-eyebrow text-ink-subtle uppercase">{{ __('ui.footer.legal_title') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($legalLinks as $link)
                            <li><a class="text-ink-muted hover:text-ink" href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </div>

        <div class="border-t border-line">
            <div class="mx-auto flex w-full max-w-content flex-col gap-2 px-gutter py-5 text-xs text-ink-subtle sm:flex-row sm:items-center sm:justify-between">
                <p>{{ __('ui.footer.copyright', ['year' => now()->year, 'app' => $app]) }}</p>

                {{-- L'attribuzione a OpenStreetMap è una condizione della licenza
                     ODbL dei dati cartografici, non un ringraziamento. --}}
                <p>
                    {!! __('ui.footer.map_attribution', [
                        'osm' => '<a class="underline hover:text-ink" href="https://www.openstreetmap.org/copyright" rel="noopener noreferrer" target="_blank">'.e(__('ui.footer.osm')).'</a>',
                        'license' => '<a class="underline hover:text-ink" href="https://opendatacommons.org/licenses/odbl/" rel="noopener noreferrer" target="_blank">'.e(__('ui.footer.odbl')).'</a>',
                    ]) !!}
                </p>
            </div>
        </div>
    </footer>
</body>
</html>
