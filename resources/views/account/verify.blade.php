{{--
    Verifica dell'indirizzo (§15.2).

    Il messaggio dice esattamente che cosa cambia: **si può salvare comunque**,
    ma finché l'indirizzo non è confermato nessun promemoria può partire. È la
    regola di `User::canReceiveNotifications()`, scritta in italiano.
--}}
<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">{{ __('account.verify.lead', ['email' => $email]) }}</p>
        </header>

        <form method="POST" action="{{ route('account.verification.send') }}">
            @csrf

            <button type="submit" class="bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('account.verify.resend') }}
            </button>
        </form>
    </div>
</x-layouts.app>
