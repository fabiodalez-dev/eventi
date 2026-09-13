@props(['event'])
@php
    $place = $event->custom_location ?? [];
    $hasCoordinates = isset($place['lat'], $place['lng']);
    $destination = $hasCoordinates ? $place['lat'].','.$place['lng'] : ($place['address'] ?? '');
    $payload = ['markers' => $hasCoordinates ? [[0, (float) $place['lng'], (float) $place['lat'], null, 1, $place['name'] ?? $place['address']]] : [], 'categories' => [], 'truncated' => false];
@endphp
<section class="flex flex-col gap-3 bg-canvas p-5 border-2 border-line" aria-label="{{ __('facebook_import.location') }}">
    <h2 class="text-xl font-semibold">{{ ($place['name'] ?? null) ?: __('facebook_import.location') }}</h2>
    <p>{{ $place['address'] }}</p>
    @if ($hasCoordinates)
        <x-events-map :city="$event->city" :filters="new \App\DTOs\EventFilters" :payload="$payload" :static="true" :show-legend="false" :center="[(float) $place['lng'], (float) $place['lat']]" :zoom="config('map.venue_zoom')" map-class="aspect-[16/10] w-full" />
    @endif
    <div class="venue-directions">
        <a class="venue-direction bg-accent text-on-accent hover:bg-brand-strong" href="{{ 'https://www.google.com/maps/dir/?'.http_build_query(['api' => '1', 'destination' => $destination]) }}" target="_blank" rel="noopener noreferrer">{{ __('common.actions.directions_google') }}</a>
        <a class="venue-direction border border-line hover:border-accent" href="{{ 'https://maps.apple.com/?'.http_build_query(['daddr' => $destination]) }}" target="_blank" rel="noopener noreferrer">{{ __('common.actions.directions_apple') }}</a>
    </div>
</section>
