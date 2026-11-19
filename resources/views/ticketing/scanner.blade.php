{{--
    Il controllo ingressi di una data (`/gestione-biglietti/{data}/scanner`),
    per chi gestisce e per il personale all'ingresso: la testata con la
    locandina e un solo ritorno, poi lo scanner in una card.
--}}
@php $timezone = $date->event->city->timezone ?? config('app.timezone'); @endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="__('ticketing.checkin')"
        :subtitle="__('ticketing.scanner_page.subtitle')"
        :back="$canManage ? route('ticketing.manage.show', $date) : route('ticketing.manage.index')"
        :back-label="$canManage ? __('ticketing.scanner_page.back_manage') : __('ticketing.manage_page.back')"
    >
        <x-card>
            <x-service-event-row :occurrence="$date" :href="false" :meta="collect([$date->starts_at->timezone($timezone)->format('d/m/Y H:i'), $date->effectiveVenue()?->name])->filter()->implode(' · ')" />
        </x-card>
        @if ($ended)
            <x-notice :title="__('ticketing.manage_page.ended_title')">{{ __('ticketing.scanner_page.ended') }}</x-notice>
        @else
            <x-card :title="__('ticketing.scan')">
                @include('ticketing.scanner-form')
            </x-card>
        @endif
    </x-service-page>
</x-layouts.app>
