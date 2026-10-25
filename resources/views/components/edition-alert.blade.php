{{-- «Avvisami alla prossima edizione», solo sugli eventi senza più date in programma. --}}
@props(['event'])
@php $alerts = app(\App\Services\Events\EditionAlerts::class); @endphp
@if ($alerts->offered($event))
    <div class="flex flex-col items-start gap-2 border-2 border-line p-4" data-edition-alert>
        <p class="m-0 text-sm text-ink-muted">{{ __('events.edition_alert.lead') }}</p>
        <x-alert-toggle :alerts="$alerts" :id="$event->getKey()" store="account.edition-alerts.store" destroy="account.edition-alerts.destroy" prefix="events.edition_alert" />
    </div>
@endif
