<article class="grid gap-5 border-b border-line py-7 sm:grid-cols-[1fr_auto]">
    <div class="min-w-0">
        <div class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-muted"><span>{{ $offer['leg_label'] }}</span><span>{{ $offer['departure_label'] }}</span></div>
        <h2 class="font-display text-2xl font-bold"><a href="{{ $offer['url'] }}">{{ $offer['zone'] }}</a></h2>
        <p class="mt-2 text-sm">{{ $offer['driver']['name'] }} @if($offer['driver']['verified'])<span class="text-ink-muted">· {{ __('carpool.verified') }}</span>@endif</p>

@if(!empty($offer['reviews']))<p class="my-3 text-sm">@if($offer['reviews']['count']){{ __('carpool.reviews.summary', ['average' => number_format($offer['reviews']['average'], 1, ',', ''), 'count' => $offer['reviews']['count']]) }} · @endif<a class="underline" href="{{ $offer['reviews_url'] }}">{{ __('carpool.reviews.open') }}</a></p>@endif

        <p class="mt-3 text-sm text-ink-muted">{{ $offer['accessibility_label'] }}</p>
        @if($offer['accessibility_note'])<p class="mt-1 text-sm">{{ $offer['accessibility_note'] }}</p>@endif
        @if(count($offer['stops']))<p class="mt-2 text-sm text-ink-muted">{{ implode(' → ', $offer['stops']) }}</p>@endif
    </div>
    <div class="flex flex-wrap items-center justify-between gap-4 sm:flex-col sm:items-end sm:justify-start">
        <span class="font-semibold">{{ __('carpool.available', ['count' => $offer['available']]) }}</span>
        <x-button variant="secondary" :href="$offer['url']">{{ $offer['is_own'] ? __('carpool.pending') : __('carpool.seek') }}</x-button>
    </div>
</article>
