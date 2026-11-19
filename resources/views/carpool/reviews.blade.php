{{-- Le recensioni di un conducente: media, numero e le recensioni, ognuna segnalabile. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('carpool.reviews.title')" :subtitle="__('carpool.web.reviews_subtitle', ['name' => $driver_name])" :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">
        <x-card data-reviews-summary>
            <div class="flex min-w-0 items-center gap-4">
                <x-avatar :name="$driver_name" size="lg" />
                <p class="font-display text-xl leading-tight font-extrabold text-ink">{{ $driver_name }}</p>
            </div>
            @if ($summary['count'])
                <x-stat-list :items="[
                    ['label' => __('carpool.web.reviews_average'), 'value' => number_format($summary['average'], 1, ',', '')],
                    ['label' => __('carpool.web.reviews_count'), 'value' => $summary['count']],
                ]" />
            @endif
            <p class="text-sm text-ink-muted">{{ __('carpool.reviews.period') }}</p>
        </x-card>

        @forelse ($reviews as $review)
            <x-card as="article" padding="sm" data-review="{{ $review['id'] }}">
                <div class="flex min-w-0 items-start gap-3">
                    <x-avatar :name="$review['name']" />
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-ink">{{ $review['name'] }}</span>
                            <x-badge tone="brand" size="sm">{{ __('carpool.web.reviews_rating', ['rating' => $review['rating']]) }}</x-badge>
                        </div>
                        <span class="text-xs text-ink-subtle">{{ __('carpool.reviews.confirmed') }}</span>
                        @if ($review['body'])<p class="text-sm whitespace-pre-wrap break-words text-ink">{{ $review['body'] }}</p>@endif
                    </div>
                </div>
                <div class="svc-card__footer pt-2">@include('carpool.partials.report', ['reviewId' => $review['id'], 'bare' => true])</div>
            </x-card>
        @empty
            <x-empty-state :title="__('carpool.reviews.none')" />
        @endforelse

        @include('carpool.partials.pages')
    </x-service-page>
</x-layouts.app>
