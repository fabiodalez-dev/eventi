@props(['city'])
@php
    $slug = static fn ($value) => $value instanceof \Illuminate\Database\Eloquent\Model ? $value->getRouteKey() : (is_string($value) ? mb_substr($value, 0, 255) : null);
    $categoryContext = $slug(request()->route('category') ?? request()->query('category'));
    $tagContext = $slug(request()->route('tag') ?? request()->query('tag'));
    $venueContext = $slug(request()->routeIs('venues.show', 'city.venues.show') ? request()->route('slug') : request()->query('venue'));
@endphp
<aside hidden data-live-sponsorship
    data-city="{{ $city->slug }}"
    data-endpoint="{{ route('city.sponsorships.banner', ['platform' => 'web', 'city' => $city->slug, 'exclude_event' => request()->routeIs('events.show', 'city.events.show') ? request()->route('slug') : null, 'category' => $categoryContext, 'venue' => $venueContext, 'tag' => $tagContext]) }}"
    data-metric-base="{{ url('/api/v1/reports/sponsorships') }}"
    aria-label="Evento sponsorizzato"
    class="mx-auto my-8 w-full max-w-content px-gutter">
    <a rel="sponsored" class="relative flex min-w-0 items-center gap-4 border-2 border-line bg-canvas-deep p-3 text-ink no-underline hover:border-accent focus-visible:outline-2 focus-visible:outline-accent">
        <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden bg-canvas text-accent sm:size-24" aria-hidden="true">
            <span class="font-display text-sm font-extrabold">inCittà</span>
            <img hidden alt="" class="size-full object-contain grayscale" loading="lazy" width="96" height="96">
        </div>
        <div class="min-w-0 flex-1 pr-6">
            <span class="absolute top-2 right-2 font-display text-xs font-extrabold" aria-label="Pubblicità">AD</span>
            <p data-banner-title class="break-words font-display text-lg leading-tight font-extrabold sm:text-xl"></p>
            <p data-banner-when class="mt-1 text-sm font-semibold"></p>
            <p data-banner-place class="break-words text-sm text-ink-muted"></p>
            <p data-banner-by class="mt-1 text-xs text-ink-subtle"></p>
        </div>
    </a>
</aside>
