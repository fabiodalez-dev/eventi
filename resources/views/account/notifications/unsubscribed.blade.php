{{--
    L'esito della disiscrizione a un click (§15.9).

    La pagina conferma ciò che è già avvenuto: il collegamento nell'email non
    porta a una richiesta di conferma, perché una promessa di «un click» che ne
    richiede due è il momento in cui un messaggio viene segnato come spam.
--}}
<x-layouts.app :meta="$meta">
    <div class="mx-auto flex w-full max-w-xl flex-col gap-4">
        <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>

        @if ($stopped && $type !== null)
            <p class="text-sm text-ink-muted">{{ __('notifications.unsubscribed.body', ['type' => $type->label()]) }}</p>
        @else
            <p class="text-sm text-ink-muted">{{ __('notifications.unsubscribed.mandatory') }}</p>
        @endif

        <a class="self-start bg-surface px-4 py-2.5 text-sm font-semibold text-ink border-2 border-line transition hover:border-accent" href="{{ $preferencesUrl }}">
            {{ __('notifications.unsubscribed.undo') }}
        </a>
    </div>
</x-layouts.app>
