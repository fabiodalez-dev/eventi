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
    if ($city !== null && ! app(\App\Services\Seo\EditorialContent::class)->indexable($city)) {
        $robots = 'noindex, follow';
    }
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
        'feeds.wizard' => __('subscriptions.footer'),
        'account.notifications' => __('subscriptions.notifications'),
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
<html data-theme="{{ auth()->user()?->appearance === 'light' ? 'light' : 'dark' }}" data-theme-user="{{ auth()->id() ?? 'guest' }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if (filled($city?->seo['google_verification'] ?? null))
        <meta name="google-site-verification" content="{{ $city->seo['google_verification'] }}">
    @endif
    <meta name="color-scheme" content="dark light">

    {{-- Il colore della barra del browser su Android. Sta **prima** dello
         script qui sotto, che è quello che lo corregge per chi ha scelto un
         tema diverso da quello del sistema: un `media="(prefers-color-scheme)"`
         seguirebbe il sistema operativo e non la scelta fatta qui. --}}
    <meta name="theme-color" content="{{ auth()->user()?->appearance === 'light' ? '#faf9f6' : '#0b0b0b' }}">

    {{-- Le icone del sito.

         `favicon.ico` è rimasto per anni un file da **zero byte**: i browser
         lo chiedono da soli a `/favicon.ico` e ricevevano un file vuoto, cioè
         il mappamondo grigio in ogni scheda e accanto a ogni risultato di
         ricerca su telefono. `notification-badge.png` è il distintivo
         monocromatico che Android mette nella barra di stato — è una
         maschera, del file conta solo la trasparenza. --}}
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @if (\Illuminate\Support\Facades\Route::has('webmanifest'))
        <link rel="manifest" href="{{ route('webmanifest') }}">
    @endif
    {{-- Applied before CSS paints. Guests share cached HTML, never preferences. --}}
    <script @cspNonce>
        (() => {
            const root = document.documentElement;
            if (root.dataset.themeUser === 'guest') {
                const cookie = document.cookie.split('; ').find(value => value.startsWith('incitta_appearance='))?.split('=')[1];
                let saved = cookie;
                if (saved !== 'light' && saved !== 'dark') {
                    try { saved = localStorage.getItem('incitta:appearance:guest'); } catch {}
                }
                root.dataset.theme = saved === 'light' || saved === 'dark' ? saved : (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
                document.cookie = 'incitta_appearance=' + root.dataset.theme + '; Path=/; Max-Age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
            }
            document.querySelector('meta[name="color-scheme"]').content = root.dataset.theme;
            document.querySelector('meta[name="theme-color"]').content = root.dataset.theme === 'light' ? '#faf9f6' : '#0b0b0b';
        })();
    </script>

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
    @if (! str_contains($robots ?? '', 'noindex'))
        <meta name="robots" content="max-image-preview:large">
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
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:locale" content="{{ app()->getLocale() === 'it' ? 'it_IT' : str_replace('-', '_', app()->getLocale()) }}">
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

    {{-- Both themes are resolved before paint, including first-visit system preference.
         Preload their Latin fonts from the same build URLs used by CSS. --}}
    @foreach (['bricolage', 'manrope', 'archivo'] as $family)
        @php
            $fontLatino = \App\Support\Fonts::latin($family);
        @endphp
        @if ($fontLatino !== null)
            <link rel="preload" as="font" type="font/woff2" href="{{ $fontLatino }}" crossorigin>
        @endif
    @endforeach

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
            @if ($lcp->preloadMedia !== null) media="{{ $lcp->preloadMedia }}" @endif
            fetchpriority="high"
        >
    @elseif ($lcp !== null)
        <link
            rel="preload"
            as="image"
            href="{{ $lcp->src }}"
            @if ($lcp->preloadMedia !== null) media="{{ $lcp->preloadMedia }}" @endif
            fetchpriority="high"
        >
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
    <x-consent-scripts />

    {{ $head ?? '' }}
</head>
<body class="min-h-dvh bg-canvas text-ink antialiased pb-[calc(4rem+env(safe-area-inset-bottom))] lg:pb-0">
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
    <header class="site-header fixed inset-x-0 top-0 z-[9000] border-b-2 border-line bg-[var(--header-surface)] backdrop-blur-2xl">
        <div class="flex h-[70px] items-center gap-[clamp(0.75rem,2vw,1.875rem)] px-[clamp(0.875rem,2.2vw,1.875rem)]">
            {{-- La citta' sta SOTTO il nome, non accanto.

                 Di fianco erano due parole sulla stessa riga e si leggevano
                 come una cosa sola, «inCitta Padova»; sotto diventa quello che
                 e': il nome, e l'edizione di cui stai guardando gli eventi.

                 `items-start` e non `items-baseline`: incolonnate a sinistra,
                 con il margine del nastro sotto a fare da riferimento. Il
                 distacco e' di tre pixel — un occhiello attaccato al nome gli
                 appartiene, uno staccato sembra una voce di menu. --}}
            <a href="{{ url('/') }}" class="flex shrink-0 flex-col items-start gap-[3px]" aria-label="{{ __('ui.header.home', ['app' => trim($app.' '.($city?->name ?? ''))]) }}">
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
                data-live-search="{{ route('search.suggestions') }}"
                data-search-loading="{{ __('search.live.loading') }}"
                data-search-error="{{ __('search.live.error') }}"
                class="relative flex h-[38px] min-w-0 max-w-[420px] flex-auto items-center border-2 border-line pl-2.5 focus-within:border-accent"
            >
                <label for="site-search" class="sr-only">
                    {{ __('ui.header.search_label', ['city' => $city?->name ?? $app]) }}
                </label>
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square" class="shrink-0 text-ink-subtle">
                    <circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path>
                </svg>
                <input
                    id="site-search"
                    autocomplete="off"
                    maxlength="120"
                    aria-controls="site-search-suggestions"
                    type="search"
                    name="q"
                    value="{{ request()->string('q') }}"
                    placeholder="{{ __('ui.header.search_placeholder') }}"
                    class="h-full min-w-0 flex-auto border-0 bg-transparent px-2.5 text-[0.813rem] text-ink placeholder:text-ink-subtle focus:outline-none"
                >
                <button type="submit" class="h-full shrink-0 bg-accent px-3.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase">
                    {{ __('common.actions.search') }}
                </button>
                <div id="site-search-suggestions" data-search-suggestions hidden role="region" aria-live="polite" aria-label="{{ __('search.live.label') }}" class="absolute -left-0.5 -right-0.5 top-full z-50 mt-1 max-h-[60dvh] overflow-y-auto border-2 border-line bg-canvas shadow-xl"></div>
            </form>

            {{-- Il menu dell'account: per chi non è collegato un invito ad
                 accedere, per chi lo è le proprie pagine — e le vie verso i
                 pannelli, che prima non esistevano da nessuna parte. Chi
                 amministra il sito doveva ricordarsi `/admin` e scriverlo a
                 mano. --}}
            <x-account-menu :saved-count="$savedCount" />

            @if (\Illuminate\Support\Facades\Route::has('submissions.create'))
                <a
                    href="{{ route('submissions.create') }}"
                    class="ui-action hidden h-[38px] shrink-0 items-center bg-accent px-3.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] whitespace-nowrap text-on-accent uppercase transition-colors hover:bg-brand-strong md:inline-flex"
                >
                    {{ __('ui.header.submit_event') }}
                </a>
            @endif

            <form data-appearance-form action="{{ route('appearance.update') }}" method="POST" class="shrink-0">
                @csrf
                @method('PATCH')
                <button type="{{ auth()->check() ? 'submit' : 'button' }}" name="appearance" value="{{ auth()->user()?->appearance === 'light' ? 'dark' : 'light' }}" data-appearance-toggle aria-label="Cambia tema" title="Cambia tema" class="appearance-toggle inline-flex min-h-12 min-w-12 cursor-pointer items-center justify-center text-ink-muted hover:text-ink">
                    <svg class="theme-icon-sun" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>
                    <svg class="theme-icon-moon" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 15.5A8.5 8.5 0 0 1 8.5 4a8.5 8.5 0 1 0 11.5 11.5Z"/></svg>
                </button>
                <span data-appearance-status role="status" aria-live="polite" class="sr-only"></span>
            </form>
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

        @auth
            @php($contentSelection = app(\App\Services\Account\ContentPreferences::class)->selection(auth()->user()))
            @if($contentSelection['mode'] === 'selected' || $contentSelection['hidden_categories'] !== [])
                <p class="bg-surface px-gutter py-3 text-sm">Stai esplorando gli eventi secondo i tuoi interessi. <a class="font-bold underline" href="{{ route('account.content-preferences') }}">Modifica o mostra tutto</a></p>
            @endif
        @endauth
        @if(auth()->check() && ! request()->routeIs('account.profile') && request()->routeIs('account.*', 'tickets.*', 'ticketing.manage.*', 'appearance', 'google-calendar.*', 'verification.notice', 'notifications.preferences'))
            <a href="{{ route('account.profile') }}" class="mb-6 inline-flex min-h-12 items-center gap-2 font-semibold text-brand" data-profile-back><span aria-hidden="true">←</span> Il mio profilo</a>
        @endif
        {{ $slot }}

        @if (! ($wide ?? false))
            </div>
        @endif
        @if ($city !== null && ! request()->routeIs('events.preview') && request()->routeIs('home', 'events.*', 'venues.*', 'search', 'categories.*', 'tags.*', 'account.profile', 'city.home', 'city.events.*', 'city.venues.*', 'city.search', 'city.categories.*', 'city.tags.*'))
            <x-sponsorship-banner :city="$city" />
        @endif
    </main>

    <p data-appearance-error hidden role="alert" class="fixed inset-x-4 bottom-24 z-[9999] mx-auto max-w-lg border border-line bg-canvas p-4 text-sm text-ink"></p>
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
    <x-mobile-navigation />
</body>
</html>
