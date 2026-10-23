{{-- `/sono-qui/{token}`: dove raggiungere qualcuno a un evento. Solo il locale, mai il telefono. --}}
<x-layouts.app :narrow="true" :meta="$meta">
    @php
        $date = $share->occurrence;
        $event = $date->event;
        $venue = $date->effectiveVenue();
        $timezone = $event->city?->timezone ?? config('app.timezone');
        $end = $date->effective_ends_at ?? $date->ends_at;
        $directions = $venue !== null && $venue->lat !== null ? 'https://www.google.com/maps/dir/?api=1&destination='.$venue->lat.','.$venue->lng : null;
    @endphp
    <div class="mx-auto flex max-w-md flex-col gap-4">
        <p class="text-eyebrow">{{ $share->display_name ? __('presence.who', ['name' => $share->display_name]) : __('presence.someone') }}</p>
        <h1 class="text-hero">{{ $event->title }}</h1>
        <p class="font-semibold">{{ $date->starts_at->timezone($timezone)->format('H:i') }}@if ($end) – {{ $end->timezone($timezone)->format('H:i') }}@endif · {{ $date->starts_at->timezone($timezone)->translatedFormat('l j F') }}</p>
        @if ($venue)
            <p><strong>{{ $venue->name }}</strong><br>{{ $venue->address }}, {{ $venue->municipality }}</p>
        @endif
        @if ($share->note)
            <p class="border-l-4 border-accent bg-surface p-3">{{ $share->note }}</p>
        @endif
        <div class="flex flex-wrap gap-3">
            @if ($directions)<x-button :href="$directions" class="min-h-12">{{ __('presence.directions') }}</x-button>@endif
            <x-button :href="\App\Support\EventUrl::occurrence($date)" variant="secondary" class="min-h-12">{{ __('presence.open_event') }}</x-button>
        </div>
        <p class="text-xs text-ink-muted">{{ __('presence.privacy', ['time' => $share->expires_at->timezone($timezone)->format('H:i')]) }}</p>
    </div>
</x-layouts.app>
