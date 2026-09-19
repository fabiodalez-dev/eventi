<x-layouts.app :meta="$meta">@include('carpool.nav')<div class="w-full max-w-3xl">
<h1 class="text-hero">{{ __('carpool.reviews.title') }}</h1><p class="mt-4 text-xl font-bold">{{ $driver_name }}</p>
@if($summary['count'])<p class="my-4 text-lg">{{ __('carpool.reviews.summary', ['average' => number_format($summary['average'], 1, ',', ''), 'count' => $summary['count']]) }}</p>@endif
<p class="my-4 text-sm text-ink-muted">{{ __('carpool.reviews.period') }}</p>
<div class="divide-y divide-line">@forelse($reviews as $review)<article class="space-y-3 py-6"><p class="font-bold">{{ $review['name'] }} · {{ $review['rating'] }}/5</p><p class="text-sm text-ink-muted">{{ __('carpool.reviews.confirmed') }}</p>@if($review['body'])<p class="whitespace-pre-wrap break-words">{{ $review['body'] }}</p>@endif
@include('carpool.partials.report', ['reviewId' => $review['id'], 'offerId' => null, 'requestId' => null])</article>@empty<p class="py-6">{{ __('carpool.reviews.none') }}</p>@endforelse</div>
<div class="my-6 flex gap-4">@if($page > 1)<x-button variant="secondary" :href="request()->fullUrlWithQuery(['page' => $page - 1])">{{ __('pagination.previous') }}</x-button>@endif @if($has_more)<x-button variant="secondary" :href="request()->fullUrlWithQuery(['page' => $page + 1])">{{ __('pagination.next') }}</x-button>@endif</div>
</div></x-layouts.app>
