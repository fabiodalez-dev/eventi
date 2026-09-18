<div class="mb-7 space-y-3 border-b border-line pb-6">
    <p class="text-sm font-semibold text-accent">{{ __('carpool.free') }} · {{ $offer['leg_label'] }}</p>
    <h1 class="font-display text-3xl font-bold leading-tight sm:text-4xl">{{ $offer['event_title'] }}</h1>
    <p class="text-lg">{{ $offer['zone'] }} · {{ $offer['departure_label'] }}</p>
    <p class="text-sm text-ink-muted">{{ $offer['event_location'] }} · {{ $offer['status_label'] }}</p>
    <div class="flex flex-wrap gap-5 text-sm font-semibold">
        @if($offer['event_url'])<a class="inline-flex min-h-12 items-center underline" href="{{ $offer['event_url'] }}">{{ __('carpool.event') }}</a>@endif
        <a class="inline-flex min-h-12 items-center underline" href="{{ $offer['map_url'] }}" rel="noopener" target="_blank">{{ __('carpool.map') }}</a>
    </div>
</div>

@if(!empty($offer['reviews']))<p class="my-3 text-sm">@if($offer['reviews']['count']){{ __('carpool.reviews.summary', ['average' => number_format($offer['reviews']['average'], 1, ',', ''), 'count' => $offer['reviews']['count']]) }} · @endif<a class="underline" href="{{ $offer['reviews_url'] }}">{{ __('carpool.reviews.open') }}</a></p>@endif
