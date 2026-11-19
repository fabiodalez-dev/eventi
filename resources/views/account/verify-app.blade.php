{{-- La conferma dell'indirizzo aperta nel browser del telefono: il pulsante
     la riporta nell'app con un `intent://` che nomina il pacchetto. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('account.verify.app_title')" :subtitle="__('account.verify.app_lead')">
        <x-card :title="__('auth.verify.app_card_title')" :description="__('auth.verify.app_card_description')">
            <x-slot:footer>
                <x-form-actions>
                    <x-button :href="$intent" rel="noreferrer">{{ __('account.verify.app_open') }}</x-button>
                    <x-button variant="secondary" :href="$fallback">{{ __('account.verify.app_continue') }}</x-button>
                </x-form-actions>
            </x-slot:footer>
        </x-card>
    </x-service-page>
</x-layouts.app>
