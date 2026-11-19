{{-- `/sono-qui/{token}`: dove raggiungere qualcuno a un evento. Solo il locale, mai il telefono. L'evento con la locandina, l'orario, il locale con le indicazioni e la nota di chi aspetta. --}}
@php
    $date = $share->occurrence;
    $event = $date->event;
    $venue = $date->effectiveVenue();
    $timezone = $event->city?->timezone ?? config('app.timezone');
    $end = $date->effective_ends_at ?? $date->ends_at;
    $directions = $venue !== null && $venue->lat !== null ? 'https://www.google.com/maps/dir/?api=1&destination='.$venue->lat.','.$venue->lng : null;
    $when = $date->starts_at->timezone($timezone)->format('H:i').($end ? ' – '.$end->timezone($timezone)->format('H:i') : '').' '.__('common.separator').' '.$date->starts_at->timezone($timezone)->translatedFormat('l j F');
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$event->title" :eyebrow="$share->display_name ? __('presence.who', ['name' => $share->display_name]) : __('presence.someone')">
        <x-card padding="sm">
            <x-service-event-row :occurrence="$date" :meta="$when">
                @if ($venue)
                    <span><strong class="text-ink">{{ $venue->name }}</strong><br>{{ $venue->address }}, {{ $venue->municipality }}</span>
                @endif
            </x-service-event-row>
            @if ($share->note)
                <p class="m-0 border-l-4 border-accent pl-3 text-ink">{{ $share->note }}</p>
            @endif
        </x-card>
        <x-form-actions>
            @if ($directions)
                <x-button :href="$directions" target="_blank" rel="noopener noreferrer"><x-lucide name="navigation" class="size-4" />{{ __('presence.directions') }}</x-button>
            @endif
            <x-button :href="\App\Support\EventUrl::occurrence($date)" variant="secondary">{{ __('presence.open_event') }}</x-button>
        </x-form-actions>
        <p class="m-0 text-sm text-ink-muted">{{ __('presence.privacy', ['time' => $share->expires_at->timezone($timezone)->format('H:i')]) }}</p>
    </x-service-page>
</x-layouts.app>
