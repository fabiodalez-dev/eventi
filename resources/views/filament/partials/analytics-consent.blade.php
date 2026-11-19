{{--
    Cosa contano le statistiche, sempre visibile sopra i numeri: le aperture
    dei link brevi si contano per tutti, come totale anonimo; visite e azioni
    solo per chi ha accettato le statistiche, quindi i numeri veri sono più
    alti di quelli mostrati.
--}}
<x-filament::callout color="info" icon="heroicon-o-information-circle" :heading="__('panel.analytics.consent_title')" data-analytics-consent>
    <x-slot name="description">
        <ul class="flex list-disc flex-col gap-1.5 ps-5">
            <li>{{ __('panel.analytics.consent_links') }}</li>
            <li>{{ __('panel.analytics.consent_views') }}</li>
        </ul>
    </x-slot>
</x-filament::callout>
