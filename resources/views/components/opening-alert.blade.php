{{-- «Avvisami quando aprono le prenotazioni», solo sulle date con l'apertura ancora davanti. --}}
@props(['occurrence', 'event'])
@php
    $occurrence->setRelation('event', $event);
    $alerts = app(\App\Services\Ticketing\BookingOpeningAlerts::class);
@endphp
@if ($alerts->opensLater($occurrence))
    <div class="flex flex-col items-start gap-1 sm:items-end" data-opening-alert>
        <x-alert-toggle :alerts="$alerts" :id="$occurrence->getKey()" store="account.opening-alerts.store" destroy="account.opening-alerts.destroy" prefix="ticketing.opening" />
    </div>
@endif
