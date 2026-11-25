{{--
    La pagina a cui Stripe riporta chi ha pagato una sponsorizzazione
    dall'app (`/app/sponsorizzazioni/ritorno?subject=venue&id=…&order=…`,
    con `&cancelled=1` se ha chiuso la pagina di pagamento).

    L'app apre il pagamento in una scheda del browser; questo indirizzo è
    dichiarato App Link, quindi su un telefono verificato si apre già
    nell'app. Quando il browser lo mostra lo stesso (redirect dentro la
    scheda, App Links non verificati) il pulsante riporta nell'app con un
    `intent://` che nomina il pacchetto, come la pagina di ripiego
    dell'accesso (`account.mobile-magic-link`). La pagina non tocca l'ordine:
    il pagamento lo controlla l'app, con il suo token, chiamando
    `…/sponsorships/orders/{order}/reconcile`; e il webhook di Stripe arriva
    comunque. Nell'indirizzo non ci sono segreti, solo numeri.

    **Autonoma di proposito**, come la pagina dell'accesso: niente testata,
    piè di pagina o script di terzi del layout del sito.
--}}
@php
    $app = config('app.name');
@endphp
<!doctype html>
<html lang="it" data-theme="dark" data-theme-auto>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#0b0b0b">
    <script @cspNonce>
        (() => {
            const root = document.documentElement;
            let saved = document.cookie.split('; ').find(value => value.startsWith('incitta_appearance='))?.split('=')[1];
            if (saved !== 'light' && saved !== 'dark') {
                try { saved = localStorage.getItem('incitta:appearance:guest'); } catch {}
            }
            root.dataset.theme = saved === 'light' || saved === 'dark' ? saved : (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
            document.querySelector('meta[name="color-scheme"]').content = root.dataset.theme;
            document.querySelector('meta[name="theme-color"]').content = root.dataset.theme === 'light' ? '#faf9f6' : '#0b0b0b';
        })();
    </script>
    <title>{{ __('promotions.app_return.title') }} — {{ $app }}</title>
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
        <x-service-page :title="__($cancelled ? 'promotions.app_return.heading_cancelled' : 'promotions.app_return.heading_paid')"
            :subtitle="__($cancelled ? 'promotions.app_return.lead_cancelled' : 'promotions.app_return.lead_paid')" :show-errors="false">
            <x-card>
                <p class="text-sm text-ink-muted">{{ __('promotions.app_return.fallback') }}</p>
                <x-slot:footer>
                    <x-form-actions>
                        @if ($intent !== null)
                            <x-button id="torna-app" :href="$intent" rel="noreferrer" data-sponsorship-app-return>
                                <x-lucide name="external-link" class="size-4" />
                                {{ __('promotions.app_return.open') }}
                            </x-button>
                        @endif
                        <x-button variant="ghost" :href="url('/')" rel="noreferrer">{{ __('promotions.app_return.home') }}</x-button>
                    </x-form-actions>
                </x-slot:footer>
            </x-card>
        </x-service-page>
    </main>
</body>
</html>
