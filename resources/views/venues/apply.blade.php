{{-- `/registra-il-tuo-locale` (§11.1), e con lo stesso modulo la rivendicazione
     di un locale già a catalogo (`$claim`) e la richiesta di un organizzatore
     senza sede (`$organizer`): cambiano destinazione, campi mostrati e testi. --}}
@php
    $claim = $claim ?? null;
    $organizer = $organizer ?? false;
    $action = $claim ? route('venues.claim.store', $claim) : ($organizer ? route('organizer-applications.store') : route('venue-applications.store'));
    $turnstile = $claim ? 'registrazione-locale' : ($organizer ? 'registrazione-organizzatore' : 'registrazione-locale');
    $submit = $claim ? __('forms.claim.submit') : ($organizer ? __('forms.organizer_application.submit') : __('forms.application.submit'));
@endphp
<x-layouts.app :narrow="true" :meta="$meta">
    <header class="flex max-w-prose flex-col gap-2">
        <h1 class="text-balance text-hero text-ink">{{ $meta->heading }}</h1>
        <p class="text-sm text-ink-muted">{{ $meta->description }}</p>
    </header>

    @if ($errors->any())
        <p role="alert" class="mt-6 bg-live-soft px-4 py-3 text-sm font-semibold text-on-live-soft">
            {{ __('forms.has_errors') }}
        </p>
    @endif

    <form method="POST" action="{{ $action }}" class="mt-6 grid max-w-2xl gap-4">
        @csrf
        <x-honeypot />

        @if ($claim)
            <input type="hidden" name="venue_name" value="{{ $claim->name }}">
            <p class="border-2 border-line bg-canvas px-4 py-3 text-sm"><strong>{{ $claim->name }}</strong> · {{ $claim->address }}, {{ $claim->municipality }}</p>
        @elseif ($organizer)
            <x-field name="venue_name" :label="__('forms.organizer_application.fields.venue_name')" :required="true" />
            <x-field type="url" name="website" :label="__('forms.organizer_application.fields.website')" placeholder="https://" />
        @else
            <x-field name="venue_name" :label="__('forms.application.fields.venue_name')" :required="true" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field
                    name="type"
                    :label="__('forms.application.fields.type')"
                    :options="$types"
                    :placeholder-option="__('forms.application.type_unknown')"
                />

                <x-field type="url" name="website" :label="__('forms.application.fields.website')" placeholder="https://" />
            </div>

            <x-field name="address" :label="__('forms.application.fields.address')" autocomplete="street-address" />
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="contact_name" :label="__('forms.application.fields.contact_name')" :required="true" autocomplete="name" />
            <x-field name="contact_role" :label="__('forms.application.fields.contact_role')" />
            <x-field type="tel" name="contact_phone" :label="__('forms.application.fields.contact_phone')" autocomplete="tel" />
            <x-field type="email" name="contact_email" :label="__('forms.application.fields.contact_email')" :required="true" autocomplete="email" />
        </div>

        <x-field
            type="textarea"
            name="message"
            rich
            :label="$organizer ? __('forms.organizer_application.fields.message') : __('forms.application.fields.message')"
            :hint="__('forms.application.hints.message')"
        />

        <x-turnstile :action="$turnstile" />

        <p class="text-xs text-ink-subtle">{{ __('forms.privacy_note') }}</p>

        <button
            type="submit"
            class="justify-self-start bg-brand px-5 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
        >
            {{ $submit }}
        </button>
    </form>
</x-layouts.app>
