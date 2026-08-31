{{-- `/registra-il-tuo-locale` (§11.1). --}}
<x-layouts.app :meta="$meta">
    <header class="flex max-w-prose flex-col gap-2">
        <h1 class="text-balance text-hero text-ink">{{ $meta->heading }}</h1>
        <p class="text-sm text-ink-muted">{{ $meta->description }}</p>
    </header>

    @if ($errors->any())
        <p role="alert" class="mt-6 rounded-card bg-live-soft px-4 py-3 text-sm font-semibold text-on-live-soft">
            {{ __('forms.has_errors') }}
        </p>
    @endif

    <form method="POST" action="{{ route('venue-applications.store') }}" class="mt-6 grid max-w-2xl gap-4">
        @csrf
        <x-honeypot />

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

        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="contact_name" :label="__('forms.application.fields.contact_name')" :required="true" autocomplete="name" />
            <x-field name="contact_role" :label="__('forms.application.fields.contact_role')" />
            <x-field type="tel" name="contact_phone" :label="__('forms.application.fields.contact_phone')" autocomplete="tel" />
            <x-field type="email" name="contact_email" :label="__('forms.application.fields.contact_email')" :required="true" autocomplete="email" />
        </div>

        <x-field
            type="textarea"
            name="message"
            :label="__('forms.application.fields.message')"
            :hint="__('forms.application.hints.message')"
        />

        <x-turnstile />

        <p class="text-xs text-ink-subtle">{{ __('forms.privacy_note') }}</p>

        <button
            type="submit"
            class="justify-self-start rounded-pill bg-brand px-5 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
        >
            {{ __('forms.application.submit') }}
        </button>
    </form>
</x-layouts.app>
