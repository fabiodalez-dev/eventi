{{--
    La pagina di ripiego del collegamento dell'app (`/app/auth/magic?token=…`).

    Si vede quando Android apre il collegamento nel browser invece che
    nell'app: sui telefoni dove gli App Links non sono verificati (una firma
    non elencata in `assetlinks.json`, o una verifica non ancora rifatta dopo
    l'installazione). Chi si è appena registrato dall'app non
    può «accedere con la password», che resta rifiutata finché l'indirizzo non
    è confermato (D64): la pagina offre allora di riportare il collegamento
    nell'app con un `intent://` che nomina il pacchetto, come la conferma
    dell'email (`account.verify-app`). Android lo consegna solo all'app
    ufficiale, che lo scambia con il verificatore PKCE salvato sul telefono.

    Il gettone non entra nell'HTML: lo legge lo script da `location.search`,
    così la pagina resta uguale per ogni collegamento e non lo riflette in una
    cache o in un registro. La rotta manda `no-store` e `no-referrer`.

    **Autonoma di proposito**, come le pagine d'errore: niente testata, piè di
    pagina, statistiche o script di terzi del layout del sito, perché
    nell'indirizzo di questa pagina c'è il gettone e nessuno script oltre al
    nostro deve poterlo leggere. Del sito prende il tema (stesso script prima
    del disegno) e il foglio di stile, con card e pulsanti veri.
--}}
@php
    $user = rescue(fn () => auth()->hasUser() ? auth()->user() : null, null, false);
    $chosenTheme = in_array($user?->appearance, ['light', 'dark'], true) ? $user->appearance : null;
    $app = config('app.name');
@endphp
<!doctype html>
<html lang="it" data-theme="{{ $chosenTheme ?? 'dark' }}" @if ($chosenTheme === null) data-theme-auto @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="{{ $chosenTheme === 'light' ? '#faf9f6' : '#0b0b0b' }}">
    <script @cspNonce>
        (() => {
            const root = document.documentElement;
            if ('themeAuto' in root.dataset) {
                let saved = document.cookie.split('; ').find(value => value.startsWith('incitta_appearance='))?.split('=')[1];
                if (saved !== 'light' && saved !== 'dark') {
                    try { saved = localStorage.getItem('incitta:appearance:guest'); } catch {}
                }
                root.dataset.theme = saved === 'light' || saved === 'dark' ? saved : (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
            }
            document.querySelector('meta[name="color-scheme"]').content = root.dataset.theme;
            document.querySelector('meta[name="theme-color"]').content = root.dataset.theme === 'light' ? '#faf9f6' : '#0b0b0b';
        })();
    </script>
    <title>{{ __('auth.mobile_magic.title') }} — {{ $app }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    {{ rescue(fn () => app(\Illuminate\Foundation\Vite::class)('resources/css/app.css'), '', false) }}
</head>
<body class="min-h-dvh bg-canvas text-ink antialiased">
    <header class="border-b-2 border-line">
        <div class="mx-auto flex h-[70px] w-full max-w-service items-center px-gutter">
            <a href="{{ url('/') }}" rel="noreferrer" class="font-display text-[1.625rem] leading-none font-extrabold tracking-[-0.05em] text-ink no-underline">{{ $app }}</a>
        </div>
    </header>
    <main id="contenuto" class="mx-auto w-full max-w-service px-gutter pt-[clamp(2.5rem,6vw,5rem)] pb-16">
        <x-service-page :title="__('auth.mobile_magic.heading')" :subtitle="__('auth.mobile_magic.lead')" :show-errors="false">
            <x-card>
                <p class="text-sm text-ink-muted">{{ __('auth.mobile_magic.fallback') }}</p>
                <x-slot:footer>
                    <x-form-actions>
                        <x-button id="apri-app" :href="route('account.magic-link')" rel="noreferrer" data-package="{{ config('account.android_package') }}" data-fallback="{{ route('account.magic-link') }}">
                            <x-lucide name="external-link" class="size-4" />
                            {{ __('auth.mobile_magic.open') }}
                        </x-button>
                        <x-button variant="secondary" :href="route('account.magic-link')" rel="noreferrer">{{ __('auth.mobile_magic.site') }}</x-button>
                        <x-button variant="ghost" :href="url('/')" rel="noreferrer">{{ __('auth.mobile_magic.home') }}</x-button>
                    </x-form-actions>
                </x-slot:footer>
            </x-card>
        </x-service-page>
    </main>
    <script @cspNonce>
        (function () {
            var link = document.getElementById('apri-app');
            var token = new URLSearchParams(window.location.search).get('token') || '';
            if (!link || !/^[A-Za-z0-9]{64}$/.test(token)) {
                return;
            }
            link.href = 'intent://auth/magic?token=' + encodeURIComponent(token)
                + '#Intent;scheme=incitta;package=' + link.dataset.package
                + ';S.browser_fallback_url=' + encodeURIComponent(link.dataset.fallback) + ';end';
        })();
    </script>
</body>
</html>
