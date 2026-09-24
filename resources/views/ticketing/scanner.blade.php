<x-layouts.app :meta="$meta">
    <a class="inline-flex min-h-12 items-center underline" href="{{ route('ticketing.manage.index') }}">{{ __('ticketing.manage') }}</a>
    <h1 class="text-hero">{{ __('ticketing.checkin') }}</h1>
    <p class="mt-4 font-bold">{{ $date->event->title }}</p>
    <p>{{ $date->starts_at->timezone($date->event->city->timezone)->format('d/m/Y H:i') }}</p>
    @include('ticketing.errors')
    @include('ticketing.scanner-form')
</x-layouts.app>
