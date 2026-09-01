{{-- "Segnala un errore" (§11.5, §14.6). Nessun account richiesto. --}}
<x-layouts.app :meta="$meta">
    <header class="flex max-w-prose flex-col gap-2">
        <h1 class="text-balance text-hero text-ink">{{ $meta->heading }}</h1>
        <p class="text-sm text-ink-muted">{{ $meta->description }}</p>
    </header>

    @if ($errors->any())
        <p role="alert" class="mt-6 bg-live-soft px-4 py-3 text-sm font-semibold text-on-live-soft">
            {{ __('forms.has_errors') }}
        </p>
    @endif

    <form method="POST" action="{{ $action }}" class="mt-6 grid max-w-xl gap-4">
        @csrf
        <x-honeypot />

        <x-field
            name="reason"
            :label="__('forms.report.fields.reason')"
            :options="$reasons"
            :placeholder-option="__('forms.report.reason_placeholder')"
            :required="true"
        />

        <x-field
            type="textarea"
            name="note"
            :label="__('forms.report.fields.note')"
            :hint="__('forms.report.hints.note')"
        />

        <x-field
            type="email"
            name="reporter_email"
            :label="__('forms.report.fields.reporter_email')"
            :hint="__('forms.report.hints.reporter_email')"
            autocomplete="email"
        />

        <x-turnstile />

        <p class="text-xs text-ink-subtle">{{ __('forms.privacy_note') }}</p>

        <div class="flex flex-wrap items-center gap-3">
            <button
                type="submit"
                class="bg-brand px-5 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
            >
                {{ __('forms.report.submit') }}
            </button>

            <a href="{{ $back }}" class="text-sm font-semibold text-ink-muted underline hover:text-ink">
                {{ __('common.actions.back') }}
            </a>
        </div>
    </form>
</x-layouts.app>
