{{--
    Lo scheletro delle pagine d'errore.

    È autonomo di proposito: non estende il layout del sito, che per
    disegnare testata e piè di pagina interroga il database (città, pagine
    legali, salvataggi). Una pagina d'errore deve uscire anche quando il
    database è proprio la cosa che si è rotta, quindi qui non parte nessuna
    query: l'utente si legge solo se è già stato caricato (`hasUser()`), e
    tutto il resto viene dalla configurazione e dalle rotte.

    Il tema è lo stesso del sito, con lo stesso script prima del disegno:
    nessuna pagina bianca in mezzo a un sito scuro, o viceversa.

    Variabili: `key` (la voce di `lang/it/errors.php`: `404`, `4xx`…, o
    `link` per un collegamento firmato scaduto) e `exception`, che Laravel
    passa a ogni vista d'errore.
--}}
@php
    $exception ??= null;
    $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : (int) $key;
    $status = $status > 0 ? $status : 500;
    // Il 405 non ha una vista propria (ricade su `4xx`) ma un testo sì.
    $key = $key === '4xx' && $status === 405 ? '405' : $key;
    $user = rescue(fn () => auth()->hasUser() ? auth()->user() : null, null, false);
    $chosenTheme = in_array($user?->appearance, ['light', 'dark'], true) ? $user->appearance : null;
    $app = config('app.name');

    /*
     * Il messaggio dell'eccezione si mostra solo nel 403, e solo quando dice
     * qualcosa di più del testo predefinito in inglese: è lì che i pannelli
     * spiegano a chi sono riservati. Un 5xx non mostra mai il messaggio, che
     * può contenere dettagli interni.
     */
    $defaults = ['', 'This action is unauthorized.', 'Forbidden', 'Forbidden.', 'Unauthorized.', 'User does not have the right roles.', 'User does not have the right permissions.', 'User does not have any of the necessary access rights.', 'User is not logged in.', 'Invalid signature.'];
    $detail = $key === '403' && $exception !== null && ! in_array(trim($exception->getMessage()), $defaults, true)
        ? trim($exception->getMessage())
        : null;

    $previous = (string) request()->headers->get('referer', '');
    $previousHost = $previous !== '' ? parse_url($previous, PHP_URL_HOST) : null;
    $back = is_string($previousHost) && strcasecmp($previousHost, request()->getHost()) === 0 && $previous !== request()->fullUrl() ? $previous : null;

    $events = \Illuminate\Support\Facades\Route::has('events.index') ? route('events.index') : url('/');
    $login = \Illuminate\Support\Facades\Route::has('login') ? route('login') : null;
    $logout = $user !== null && \Illuminate\Support\Facades\Route::has('account.logout') && request()->hasSession() ? route('account.logout') : null;

    $retryAfter = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? ($exception->getHeaders()['Retry-After'] ?? null) : null;
    $retryAfter = is_numeric($retryAfter) ? (int) $retryAfter : null;
@endphp
<!DOCTYPE html>
<html data-theme="{{ $chosenTheme ?? 'dark' }}" data-theme-user="{{ $user?->getKey() ?? 'guest' }}" @if ($chosenTheme === null) data-theme-auto @endif lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
    <title>{{ __('errors.'.$key.'.title') }} — {{ $app }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    {{ rescue(fn () => app(\Illuminate\Foundation\Vite::class)('resources/css/app.css'), '', false) }}
</head>
<body class="min-h-dvh bg-canvas text-ink antialiased" data-error-page="{{ $status }}">
    <header class="border-b-2 border-line">
        <div class="mx-auto flex h-[70px] w-full max-w-service items-center px-gutter">
            <a href="{{ url('/') }}" class="font-display text-[1.625rem] leading-none font-extrabold tracking-[-0.05em] text-ink no-underline">{{ $app }}</a>
        </div>
    </header>
    <main id="contenuto" class="mx-auto w-full max-w-service px-gutter pt-[clamp(2.5rem,6vw,5rem)] pb-16">
        <x-card as="section" padding="md" :tone="$key === '403' ? 'accent' : 'default'" aria-labelledby="titolo-errore" class="sm:p-8">
            <p class="m-0 text-eyebrow text-accent uppercase">{{ __('errors.eyebrow', ['code' => $status]) }}</p>
            <h1 id="titolo-errore" class="m-0 text-hero text-ink">{{ __('errors.'.$key.'.title') }}</h1>
            <p class="m-0 text-base text-ink-muted sm:text-lg">{{ __('errors.'.$key.'.body') }}</p>
            @if ($detail !== null)
                <x-notice tone="info"><p class="m-0 text-base text-ink">{{ $detail }}</p></x-notice>
            @endif
            @if ($key === '429' && $retryAfter !== null)
                <p class="m-0 text-sm text-ink-muted" data-retry-after="{{ $retryAfter }}">{{ trans_choice('errors.429.wait', $retryAfter, ['seconds' => $retryAfter]) }}</p>
            @endif
            @if ($key === 'link')
                <p class="m-0 text-sm text-ink-muted">{{ __('errors.link.hint') }}</p>
            @endif
            @if ($key === '403' && $user !== null && filled($user->email))
                <p class="m-0 text-sm text-ink-muted">{{ __('errors.signed_in_as', ['email' => $user->email]) }}</p>
            @endif

            <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:flex-wrap" data-error-actions>
                @if ($key === '403')
                    @if ($user !== null && $login !== null)
                        <x-button :href="$login" :block="true">{{ __('errors.actions.area') }}</x-button>
                    @elseif ($login !== null)
                        <x-button :href="route('login', ['intended' => request()->fullUrl()])" :block="true">{{ __('errors.actions.login') }}</x-button>
                    @endif
                    <x-button variant="secondary" :href="$events" :block="true">{{ __('errors.actions.events') }}</x-button>
                @elseif ($key === 'link')
                    @if (\Illuminate\Support\Facades\Route::has('account.magic-link'))
                        <x-button :href="route('account.magic-link')" :block="true">{{ __('errors.link.request') }}</x-button>
                    @endif
                    @if ($login !== null)
                        <x-button variant="secondary" :href="$login" :block="true">{{ __('errors.link.password') }}</x-button>
                    @endif
                @elseif (in_array($key, ['413', '419'], true))
                    @if ($back !== null)
                        <x-button :href="$back" :block="true">{{ __($key === '413' ? 'errors.actions.back_to_form' : 'errors.actions.back') }}</x-button>
                    @endif
                    <x-button :variant="$back !== null ? 'secondary' : 'primary'" :href="$events" :block="true">{{ __('errors.actions.events') }}</x-button>
                @elseif (in_array($key, ['500', '503', '5xx'], true))
                    @if (request()->isMethodSafe())
                        <x-button :href="request()->fullUrl()" :block="true">{{ __('errors.actions.retry') }}</x-button>
                    @endif
                    <x-button :variant="request()->isMethodSafe() ? 'secondary' : 'primary'" :href="$events" :block="true">{{ __('errors.actions.events') }}</x-button>
                @else
                    <x-button :href="$events" :block="true">{{ __('errors.actions.events') }}</x-button>
                    @if ($back !== null)
                        <x-button variant="secondary" :href="$back" :block="true">{{ __('errors.actions.back') }}</x-button>
                    @endif
                @endif
            </div>

            @if ($key === '403' && $logout !== null)
                <x-slot:footer>
                    <form method="POST" action="{{ $logout }}" class="w-full sm:w-auto">
                        @csrf
                        <input type="hidden" name="to" value="login">
                        <x-button type="submit" variant="ghost">{{ __('errors.actions.logout') }}</x-button>
                    </form>
                </x-slot:footer>
            @endif
        </x-card>
    </main>
</body>
</html>
