{{--
    `/proponi-evento` (§11.1).

    Il modulo chiede poco di proposito: quello che serve alla redazione per
    capire di cosa si tratta e per richiamare chi ha scritto. Un modulo lungo è
    un modulo che nessuno compila. Tre card — l'evento, il luogo, il contatto —
    con un campo per riga e il pulsante in fondo.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="$meta->description" :back="auth()->check() ? route('account.profile') : null">
        <form method="POST" action="{{ route('submissions.store') }}" class="flex flex-col gap-6" data-submission-form>
            @csrf
            <x-honeypot />

            <x-card :title="__('account.service_forms.event_title')">
                <x-field name="title" :label="__('forms.submission.fields.title')" :required="true" />

                <x-field
                    type="datetime-local"
                    name="starts_at_hint"
                    :label="__('forms.submission.fields.starts_at_hint')"
                    :hint="__('forms.submission.hints.starts_at_hint')"
                />

                <x-field
                    type="textarea"
                    name="raw_text"
                    rich
                    :label="__('forms.submission.fields.raw_text')"
                    :hint="__('forms.submission.hints.raw_text')"
                />
            </x-card>

            <x-card :title="__('account.service_forms.place_title')">
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
            </x-card>

            <x-card :title="__('account.service_forms.contact_title')" :description="__('forms.privacy_note')">
                <x-field name="contact_name" :label="__('forms.submission.fields.contact_name')" autocomplete="name" />
                <x-field type="email" name="contact_email" :label="__('forms.submission.fields.contact_email')" :required="true" autocomplete="email" />
            </x-card>

            <x-turnstile action="proposta-evento" />

            <x-form-actions>
                <x-button type="submit">{{ __('forms.submission.submit') }}</x-button>
                <x-button variant="ghost" :href="route('events.index')">{{ __('account.service_forms.cancel') }}</x-button>
            </x-form-actions>
        </form>
    </x-service-page>
</x-layouts.app>
