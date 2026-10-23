{{-- «Avvisami se tornano disponibili», solo sulle date esaurite e future. --}}
@props(['occurrence', 'event'])
@php
    $occurrence->setRelation('event', $event);
    $alerts = app(\App\Services\Ticketing\AvailabilityAlerts::class);
    $esaurita = $occurrence->starts_at->isFuture() && $alerts->isSoldOut($occurrence);
@endphp
@if ($esaurita)
    <div class="flex flex-col items-start gap-1 sm:items-end">
        @auth
            @php $attivo = \App\Models\AvailabilityAlert::query()->where('user_id', auth()->id())->where('occurrence_id', $occurrence->getKey())->exists(); @endphp
            @if ($attivo)
                <p class="m-0 text-sm sm:text-right">{{ __('ticketing.availability.active') }}</p>
                <form method="POST" action="{{ route('account.availability-alerts.destroy', $occurrence->getKey()) }}">@csrf @method('DELETE')<button type="submit" class="inline-flex min-h-12 items-center text-sm underline">{{ __('ticketing.availability.cancel') }}</button></form>
            @else
                <form method="POST" action="{{ route('account.availability-alerts.store', $occurrence->getKey()) }}">@csrf<x-button type="submit" variant="secondary" class="min-h-12">{{ __('ticketing.availability.subscribe') }}</x-button></form>
            @endif
        @else
            <a href="{{ route('login', ['redirect' => url()->current()]) }}" class="inline-flex min-h-12 items-center text-sm underline">{{ __('ticketing.availability.login') }}</a>
        @endauth
    </div>
@endif
