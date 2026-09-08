<x-layouts.app :meta="$meta"><div class="flex flex-col gap-6">
    <h1 class="text-hero">{{ __('ticketing.manage') }}</h1>
    @foreach ($dates as $date)
        <a href="{{ route('ticketing.manage.show', $date) }}" class="border-t-2 border-line py-4 hover:text-brand"><strong class="text-xl">{{ $date->event->title }}</strong><br>{{ $date->starts_at->timezone($date->event->city->timezone)->format('d/m/Y H:i') }} · {{ $date->effectiveVenue()?->name }}<br><span class="text-sm">{{ $date->booking_enabled ? __('ticketing.enabled') : __('ticketing.settings') }}</span></a>
    @endforeach
    {{ $dates->links() }}
</div></x-layouts.app>
