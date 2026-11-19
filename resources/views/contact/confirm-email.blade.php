{{-- La conferma del recapito del modulo contatti. La GET mostra solo il
     pulsante: gli scanner di posta aprono i collegamenti da soli, e la
     conferma deve venire da una persona. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading">
        <x-card>
            <p class="m-0 text-base text-ink">{{ __('contact.confirmation.page_'.$state, ['name' => $name]) }}</p>
            @if ($state === 'pending' && $action !== null)
                <form method="POST" action="{{ $action }}">
                    @csrf
                    <x-form-actions>
                        <x-button type="submit">{{ __('contact.confirmation.page_submit') }}</x-button>
                    </x-form-actions>
                </form>
                <p class="m-0 text-sm text-ink-muted">{{ __('contact.confirmation.page_not_you') }}</p>
            @else
                <div><x-button variant="secondary" :href="route('home')">{{ __('account.service_forms.back_events') }}</x-button></div>
            @endif
        </x-card>
    </x-service-page>
</x-layouts.app>
