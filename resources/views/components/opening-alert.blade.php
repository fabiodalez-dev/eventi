{{-- «Avvisami quando aprono le prenotazioni», solo sulle date con l'apertura ancora davanti. --}}
@props(['occurrence', 'event'])
@php
    $occurrence->setRelation('event', $event);
    $alerts = app(\App\Services\Ticketing\BookingOpeningAlerts::class);
@endphp
@if ($alerts->opensLater($occurrence))
    <div class="flex flex-col items-start gap-1 sm:items-end" data-opening-alert>
        @auth
            @if ($alerts->active(auth()->user(), $occurrence))
                <p class="m-0 text-sm sm:text-right">{{ __('ticketing.opening.active') }}</p>
                <form method="POST" action="{{ route('account.opening-alerts.destroy', $occurrence->getKey()) }}">@csrf @method('DELETE')<button type="submit" class="inline-flex min-h-12 items-center text-sm underline">{{ __('ticketing.opening.cancel') }}</button></form>
            @else
                <form method="POST" action="{{ route('account.opening-alerts.store', $occurrence->getKey()) }}">@csrf<x-button type="submit" variant="secondary" class="min-h-12">{{ __('ticketing.opening.subscribe') }}</x-button></form>
            @endif
        @else
            <a href="{{ route('login', ['redirect' => url()->current()]) }}" class="inline-flex min-h-12 items-center text-sm underline">{{ __('ticketing.opening.login') }}</a>
        @endauth
    </div>
@endif
