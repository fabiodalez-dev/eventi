{{-- «Password dimenticata» (§15.2).

     Il messaggio di conferma è lo stesso che l'indirizzo esista o no: dire
     «questa email non risulta» significa regalare a chiunque l'elenco degli
     iscritti, una prova alla volta. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('account.forgot.lead')" :back="route('login')" :back-label="__('auth.forgot.back_login')" :show-errors="false">
        {{-- Il riepilogo sotto il titolo, con un titolo vero quando il modulo
             non è nemmeno arrivato (sessione scaduta, troppi tentativi). --}}
        <x-form-errors :title="$errors->has('form') ? __('auth.form.title') : null" />
        <x-card as="form" method="POST" action="{{ route('account.password.email') }}" :title="__('auth.forgot.card_title')" :description="__('auth.forgot.card_description')">
            @csrf

            <x-field name="email" type="email" :label="__('account.login.email')" :required="true" autocomplete="email" />

            <x-slot:footer>
                <x-form-actions>
                    <x-button type="submit">{{ __('account.forgot.submit') }}</x-button>
                    {{-- L'accesso senza password resta la via raccomandata: chi è
                         arrivato qui perché non ricorda la sua può evitare del
                         tutto di sceglierne un'altra. --}}
                    <x-button variant="secondary" :href="route('account.magic-link')">
                        <x-lucide name="mail" class="size-4" />
                        {{ __('auth.login.magic') }}
                    </x-button>
                </x-form-actions>
            </x-slot:footer>
        </x-card>
    </x-service-page>
</x-layouts.app>
