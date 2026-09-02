{{-- Il modulo della nuova password (§15.2).

     Token e indirizzo viaggiano nascosti: arrivano dal collegamento ricevuto
     via email, e chiederli di nuovo significherebbe far ricopiare a mano
     sessantaquattro caratteri. L'indirizzo si vede — in sola lettura — perche'
     chi ha piu' di una casella deve sapere per quale sta scegliendo la
     password. --}}
<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">{{ __('account.reset.lead') }}</p>
        </header>

        <form method="POST" action="{{ route('account.password.update') }}" class="flex flex-col gap-4">
            @csrf

            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">

            @if ($email !== '')
                <p class="text-sm text-ink-muted">
                    <span class="text-ink-subtle">{{ __('account.login.email') }}:</span>
                    <span class="font-semibold text-ink">{{ $email }}</span>
                </p>
            @endif

            {{-- `new-password` e non `current-password`: e' il suggerimento che
                 fa proporre al gestore di password una password nuova invece
                 di riempire il campo con quella vecchia, che qui e'
                 esattamente quella che non funziona piu'. --}}
            <x-field
                name="password"
                type="password"
                :label="__('account.reset.password')"
                :required="true"
                autocomplete="new-password"
            />

            <x-field
                name="password_confirmation"
                type="password"
                :label="__('account.reset.confirm')"
                :required="true"
                autocomplete="new-password"
            />

            <button type="submit" class="bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('account.reset.submit') }}
            </button>
        </form>
    </div>
</x-layouts.app>
