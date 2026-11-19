{{-- Il modulo della nuova password (§15.2).

     Token e indirizzo viaggiano nascosti: arrivano dal collegamento ricevuto
     via email, e chiederli di nuovo significherebbe far ricopiare a mano
     sessantaquattro caratteri. L'indirizzo si vede — in sola lettura — perché
     chi ha più di una casella deve sapere per quale sta scegliendo la
     password. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('account.reset.lead')" :show-errors="false">
        {{-- Il riepilogo sotto il titolo, con un titolo vero quando il modulo
             non è nemmeno arrivato (sessione scaduta, troppi tentativi). --}}
        <x-form-errors :title="$errors->has('form') ? __('auth.form.title') : null" />
        <x-card as="form" method="POST" action="{{ route('account.password.update') }}" :title="__('auth.reset.card_title')" :description="__('auth.reset.card_description')">
            @csrf

            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">

            @if ($email !== '')
                <p class="flex flex-col gap-0.5 text-sm">
                    <span class="text-ink-muted">{{ __('auth.reset.for_email') }}</span>
                    <span class="font-semibold break-all text-ink">{{ $email }}</span>
                </p>
            @endif

            {{-- `new-password` e non `current-password`: fa proporre al gestore
                 di password una password nuova invece di riempire il campo con
                 quella vecchia, che qui è proprio quella che non funziona più. --}}
            <x-field name="password" type="password" :label="__('account.reset.password')" :required="true" autocomplete="new-password" />
            <x-field name="password_confirmation" type="password" :label="__('account.reset.confirm')" :required="true" autocomplete="new-password" />

            <x-slot:footer>
                <x-form-actions>
                    <x-button type="submit">{{ __('account.reset.submit') }}</x-button>
                    <x-button variant="ghost" :href="route('account.password.request')">{{ __('auth.reset.request_again') }}</x-button>
                </x-form-actions>
            </x-slot:footer>
        </x-card>
    </x-service-page>
</x-layouts.app>
