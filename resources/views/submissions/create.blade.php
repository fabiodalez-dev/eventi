{{--
    `/proponi-evento` (§11.1).

    Il modulo chiede poco di proposito: quello che serve alla redazione per
    capire di cosa si tratta e per richiamare chi ha scritto. Un modulo lungo è
    un modulo che nessuno compila.
--}}
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

    <form method="POST" action="{{ route('submissions.store') }}" class="mt-6 grid max-w-2xl gap-4">
        @csrf
        <x-honeypot />

        <x-field name="title" :label="__('forms.submission.fields.title')" :required="true" />

        <x-field
            type="datetime-local"
            name="starts_at_hint"
            :label="__('forms.submission.fields.starts_at_hint')"
            :hint="__('forms.submission.hints.starts_at_hint')"
        />

        <x-field
            name="venue_id"
            :label="__('forms.submission.fields.venue_id')"
            :options="$venues->mapWithKeys(fn ($venue): array => [$venue->getKey() => $venue->name])->all()"
            :placeholder-option="__('forms.submission.venue_unknown')"
        />

        <x-field
            name="venue_hint"
            :label="__('forms.submission.fields.venue_hint')"
            :hint="__('forms.submission.hints.venue_hint')"
        />

        <x-field
            type="textarea"
            name="raw_text"
            :label="__('forms.submission.fields.raw_text')"
            :hint="__('forms.submission.hints.raw_text')"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="contact_name" :label="__('forms.submission.fields.contact_name')" autocomplete="name" />
            <x-field type="email" name="contact_email" :label="__('forms.submission.fields.contact_email')" :required="true" autocomplete="email" />
        </div>

        <x-turnstile action="proposta-evento" />

        <p class="text-xs text-ink-subtle">{{ __('forms.privacy_note') }}</p>

        <button
            type="submit"
            class="justify-self-start bg-brand px-5 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
        >
            {{ __('forms.submission.submit') }}
        </button>
    </form>
</x-layouts.app>
