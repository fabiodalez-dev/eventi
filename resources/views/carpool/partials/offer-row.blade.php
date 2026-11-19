{{--
    Un passaggio nell'elenco di una data: chi guida, da dove e a che ora, i
    posti liberi. Tutta la riga porta al passaggio. `$offer` arriva da
    `RideResource::offer()`.
--}}
<x-card.row :href="$offer['url']" data-offer-row>
    <x-slot:leading><x-avatar :name="$offer['driver']['name']" /></x-slot:leading>
    <x-slot:trailing>
        <span class="hidden text-sm font-semibold text-ink sm:inline">{{ trans_choice('carpool.web.seats_left', $offer['available'], ['count' => $offer['available']]) }}</span>
        <x-lucide name="chevron-right" class="size-5 text-ink-subtle" />
    </x-slot:trailing>
    <span class="flex flex-wrap items-center gap-2">
        <span class="font-semibold text-ink">{{ $offer['zone'] ?? $offer['event_title'] }}</span>
        @if ($offer['demo'])<x-badge tone="muted" size="sm">{{ __('carpool.demo_label') }}</x-badge>@endif
    </span>
    <span>{{ $offer['leg_label'] }}@if ($offer['departure_label']) · {{ $offer['departure_label'] }}@endif</span>
    <span>{{ __('carpool.web.with_driver', ['name' => $offer['driver']['name']]) }}@if ($offer['driver']['verified']) · {{ __('carpool.verified') }}@endif</span>
    @if (! empty($offer['reviews']['count']))
        <span>{{ __('carpool.reviews.summary', ['average' => number_format($offer['reviews']['average'], 1, ',', ''), 'count' => $offer['reviews']['count']]) }}</span>
    @endif
    <span class="font-semibold text-ink sm:hidden">{{ trans_choice('carpool.web.seats_left', $offer['available'], ['count' => $offer['available']]) }}</span>
    <span class="font-semibold text-brand">{{ $offer['is_own'] ? __('carpool.web.manage') : ($offer['demo'] ? __('carpool.demo_view') : __('carpool.web.view')) }}</span>
</x-card.row>
