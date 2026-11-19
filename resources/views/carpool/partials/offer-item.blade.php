{{--
    Un passaggio offerto, nell'elenco «I miei passaggi»: la locandina della
    serata, tratta e partenza, posti e stato, e il pulsante per gestirlo.
--}}
<x-card padding="sm" as="article" data-offer-item>
    <x-service-event-row :occurrence="$occurrences[$offer['occurrence_id']] ?? null" :title="$offer['event_title']" :href="$offer['url']">
        <x-slot:badge><x-badge :tone="$offer['status'] === 'open' ? 'brand' : 'muted'" size="sm">{{ $offer['status_label'] }}</x-badge></x-slot:badge>
        <span class="font-semibold text-ink">{{ $offer['leg_label'] }}@if ($offer['departure_label']) · {{ __('carpool.web.zone_at', ['zone' => $offer['zone'], 'time' => $offer['departure_label']]) }}@endif</span>
        <span>{{ trans_choice('carpool.web.seats_left', $offer['available'], ['count' => $offer['available']]) }} · {{ trans_choice('carpool.web.seats_offered', $offer['capacity'], ['count' => $offer['capacity']]) }}</span>
        <x-slot:actions>
            <x-button size="sm" :href="$offer['url']">{{ $offer['is_own'] ? __('carpool.web.manage') : __('carpool.web.view') }}</x-button>
        </x-slot:actions>
    </x-service-event-row>
</x-card>
