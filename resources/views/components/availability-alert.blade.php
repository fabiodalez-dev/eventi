{{-- «Avvisami se tornano disponibili», solo sulle date esaurite e future. --}}
@props(['occurrence', 'event'])
@php
    $occurrence->setRelation('event', $event);
    $alerts = app(\App\Services\Ticketing\AvailabilityAlerts::class);
@endphp
@if ($occurrence->starts_at->isFuture() && $alerts->isSoldOut($occurrence))
    <div class="flex flex-col items-start gap-1 sm:items-end">
        <x-alert-toggle :alerts="$alerts" :id="$occurrence->getKey()" store="account.availability-alerts.store" destroy="account.availability-alerts.destroy" prefix="ticketing.availability" />
    </div>
@endif
