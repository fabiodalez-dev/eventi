{{-- «Accetta l'invito» al personale di ingresso (D67): la GET mostra la
     domanda, il POST con CSRF decide. --}}
@php
    $lead = $error === null
        ? __('decision.staff_invitation.page_lead', ['event' => $date->event?->title, 'date' => $date->starts_at->timezone($date->event?->city?->timezone ?? config('app.timezone'))->translatedFormat('j F Y, H:i')])
        : null;
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="$lead">
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
                <p class="text-base text-ink-muted">{{ __('decision.staff_invitation.page_consequences') }}</p>

                <x-slot:footer>
                    <x-form-actions>
                        <x-button type="submit" name="decision" value="accept">{{ __('decision.staff_invitation.accept_action') }}</x-button>
                        <x-button type="submit" variant="secondary" name="decision" value="decline">{{ __('decision.staff_invitation.decline_action') }}</x-button>
                    </x-form-actions>
                </x-slot:footer>
            </x-card>
        @endif
    </x-service-page>
</x-layouts.app>
