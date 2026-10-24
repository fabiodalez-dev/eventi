{{-- «Avvisami alla prossima edizione», solo sugli eventi senza più date in programma. --}}
@props(['event'])
@php $alerts = app(\App\Services\Events\EditionAlerts::class); @endphp
@if ($alerts->offered($event))
    <div class="flex flex-col items-start gap-2 border-2 border-line p-4" data-edition-alert>
        <p class="m-0 text-sm text-ink-muted">{{ __('events.edition_alert.lead') }}</p>
        @auth
            @if ($alerts->active(auth()->user(), $event))
                <p class="m-0 text-sm">{{ __('events.edition_alert.active') }}</p>
                <form method="POST" action="{{ route('account.edition-alerts.destroy', $event->getKey()) }}">@csrf @method('DELETE')<button type="submit" class="inline-flex min-h-12 items-center text-sm underline">{{ __('events.edition_alert.cancel') }}</button></form>
            @else
                <form method="POST" action="{{ route('account.edition-alerts.store', $event->getKey()) }}">@csrf<x-button type="submit" variant="secondary" class="min-h-12">{{ __('events.edition_alert.subscribe') }}</x-button></form>
            @endif
        @else
            <a href="{{ route('login', ['redirect' => url()->current()]) }}" class="inline-flex min-h-12 items-center text-sm underline">{{ __('events.edition_alert.login') }}</a>
        @endauth
    </div>
@endif
