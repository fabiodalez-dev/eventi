{{-- "Segnala un errore" (§11.5, §14.6). Nessun account richiesto. Una card con i tre campi e il pulsante in fondo; il ritorno porta alla scheda da cui si è arrivati. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="$meta->description" :back="$back" :back-label="__('common.actions.back')">
        <form method="POST" action="{{ $action }}" class="flex flex-col gap-6" data-report-form>
            @csrf
            <x-honeypot />

            <x-card :title="__('account.service_forms.report_title')" :description="__('forms.privacy_note')">
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
            </x-card>

            <x-turnstile action="segnalazione" />

            <x-form-actions>
                <x-button type="submit">{{ __('forms.report.submit') }}</x-button>
                <x-button variant="ghost" :href="$back">{{ __('common.actions.back') }}</x-button>
            </x-form-actions>
        </form>
    </x-service-page>
</x-layouts.app>
