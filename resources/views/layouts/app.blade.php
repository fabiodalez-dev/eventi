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
    <meta name="color-scheme" content="light dark">

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

    {{-- Preload dell'immagine più grande sopra la piega (§11.11).

         Se ne dichiara **una sola**, in AVIF: `type` fa sì che chi non apre
         l'AVIF ignori la riga invece di scaricare un file che non userà, e per
         quei browser resta comunque `fetchpriority="high"` sull'immagine, che
         è già nell'HTML iniziale e quindi scopribile subito. Dichiararne due,
         una per formato, farebbe scaricare entrambi i file a chi li apre
         tutti e due: il preload smetterebbe di far guadagnare tempo e
         inizierebbe a farne perdere. --}}
    @php $lcp = $preload ?? null; @endphp
    @if ($lcp !== null && ($lcp->sources['image/avif'] ?? null) !== null)
        <link
            rel="preload"
            as="image"
            type="image/avif"
            imagesrcset="{{ $lcp->sources['image/avif'] }}"
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
        class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:rounded-pill focus:bg-brand focus:px-4 focus:py-2 focus:font-semibold focus:text-on-brand"
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

    <header class="sticky top-0 z-30 border-b border-line bg-canvas/85 backdrop-blur-md">
        <div class="mx-auto w-full max-w-content px-gutter">
            {{-- Sul telefono la ricerca scende su una riga propria: città e data
                 restano visibili, non si nascondono per far posto. --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 py-3">
                <a
                    href="{{ url('/') }}"
                    aria-label="{{ __('ui.header.home', ['app' => $app]) }}"
                    class="flex items-center gap-2 font-display text-lg font-bold tracking-tight text-ink"
                >
                    <span aria-hidden="true" class="size-2.5 rounded-pill bg-brand"></span>
                    {{ $app }}
                </a>

                @if ($city !== null)
                    <p class="flex min-w-0 flex-1 flex-col leading-tight">
                        <span class="truncate text-eyebrow text-ink-subtle uppercase">{{ $city->name }}</span>
                        <span class="truncate text-xs text-ink-muted sm:text-sm">{{ $today }}</span>
                    </p>
                @endif

                <form
                    action="{{ route('search') }}"
                    method="GET"
                    role="search"
                    class="order-last flex w-full min-w-0 items-center gap-2 sm:order-none sm:ml-auto sm:w-auto sm:max-w-sm sm:flex-1"
                >
                    <label for="site-search" class="sr-only">
                        {{ __('ui.header.search_label', ['city' => $city?->name ?? $app]) }}
                    </label>

                    <input
                        id="site-search"
                        type="search"
                        name="q"
                        value="{{ request()->string('q') }}"
                        placeholder="{{ __('ui.header.search_placeholder') }}"
                        class="w-full rounded-pill border border-line bg-surface px-4 py-2 text-sm text-ink placeholder:text-ink-subtle focus:border-brand focus:outline-none"
                    >

                    <button
                        type="submit"
                        class="shrink-0 rounded-pill bg-brand px-4 py-2 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
                    >
                        {{ __('common.actions.search') }}
                    </button>
                </form>
            </div>

            {{-- L'area personale (§15). Da anonimi è un solo collegamento, e
                 non un invito ripetuto: il sito funziona senza account, e il
                 momento in cui l'account serve davvero lo sceglie il riquadro
                 del terzo salvataggio, non l'intestazione. --}}
            <nav aria-label="{{ __('account.title') }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 pb-2 text-sm">
                @auth
                    <a class="font-semibold text-ink-muted hover:text-ink" href="{{ route('account.feed') }}">{{ __('account.nav.feed') }}</a>
                    <a class="font-semibold text-ink-muted hover:text-ink" href="{{ route('account.saved') }}">{{ __('account.nav.saved') }}</a>
                    <a class="font-semibold text-ink-muted hover:text-ink" href="{{ route('account.profile') }}">{{ __('account.nav.profile') }}</a>

                    <form method="POST" action="{{ route('account.logout') }}" class="contents">
                        @csrf
                        <button type="submit" class="font-semibold text-ink-subtle hover:text-ink">{{ __('account.nav.logout') }}</button>
                    </form>
                @else
                    <a class="font-semibold text-ink-muted hover:text-ink" href="{{ route('login') }}">{{ __('account.nav.login') }}</a>
                @endauth
            </nav>

            <nav aria-label="{{ __('ui.nav.label') }}" class="scroll-row gap-2 pb-3">
                @foreach ($navigation as $item)
                    @php $isCurrent = request()->routeIs($item['name']); @endphp

                    <a
                        href="{{ $item['url'] }}"
                        @if ($isCurrent) aria-current="page" @endif
                        @class([
                            'rounded-pill px-3.5 py-1.5 text-sm font-semibold whitespace-nowrap transition',
                            'bg-brand text-on-brand' => $isCurrent,
                            'bg-surface text-ink-muted ring-1 ring-line hover:text-ink hover:ring-line-strong' => ! $isCurrent,
                        ])
                    >
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        </div>
    </header>

    <main id="contenuto" class="mx-auto w-full max-w-content px-gutter py-8">
        {{-- Conferma dell'ultima azione (una proposta inviata, una segnalazione
             ricevuta): sta nel layout perché è l'unico punto che ogni pagina
             attraversa dopo un reindirizzamento. --}}
        @if (session()->has('status'))
            <p role="status" class="mb-6 rounded-card bg-free-soft px-4 py-3 text-sm font-semibold text-on-free-soft">
                {{ session('status') }}
            </p>
        @endif

        {{ $slot }}
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
