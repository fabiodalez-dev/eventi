{{-- «Accetta l'invito» (D64): la GET mostra la domanda, il POST con CSRF
     decide. Gli scanner di posta aprono il collegamento ma non premono
     pulsanti, quindi non accettano niente al posto della persona. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$meta->heading"
        :subtitle="$error === null ? __('manage.invitation.page_lead', ['venue' => $venue->name, 'role' => mb_strtolower($role->label())]) : null"
    >
        @if ($error !== null)
            <x-card tone="alert" :title="__('auth.invitation.unavailable_title')">
                <p class="text-base text-ink" role="alert">{{ $error }}</p>
                <x-slot:footer>
                    <x-button variant="secondary" :href="route('home')">{{ __('auth.invitation.home') }}</x-button>
                </x-slot:footer>
            </x-card>
        @else
            <x-card as="form" method="POST" action="{{ $action }}" :title="__('auth.invitation.card_title')">
                @csrf
                <p class="text-base text-ink-muted">{{ __('manage.invitation.page_consequences') }}</p>

                <x-slot:footer>
                    <x-form-actions>
                        <x-button type="submit" name="decision" value="accept">{{ __('manage.invitation.accept_action') }}</x-button>
                        <x-button type="submit" variant="secondary" name="decision" value="decline">{{ __('manage.invitation.decline_action') }}</x-button>
                    </x-form-actions>
                </x-slot:footer>
            </x-card>
        @endif
    </x-service-page>
</x-layouts.app>
