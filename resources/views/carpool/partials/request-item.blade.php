{{--
    Una propria richiesta di posti, nell'elenco «I miei passaggi»: la serata,
    il conducente, lo stato, la chat se è aperta.
--}}
<x-card padding="sm" as="article" data-request-item>
    <x-service-event-row :occurrence="$occurrences[$item['offer']['occurrence_id']] ?? null" :title="$item['offer']['event_title']" :href="$item['url']">
        <x-slot:badge><x-badge :tone="$item['status'] === 'accepted' ? 'brand' : ($item['status'] === 'pending' ? 'soon' : 'muted')" size="sm">{{ $item['status_label'] }}</x-badge></x-slot:badge>
        <span class="font-semibold text-ink">{{ __('carpool.web.with_driver', ['name' => $item['offer']['driver']['name']]) }}</span>
        @if ($item['offer']['departure_label'])
            <span>{{ $item['offer']['leg_label'] }} · {{ __('carpool.web.zone_at', ['zone' => $item['offer']['zone'], 'time' => $item['offer']['departure_label']]) }}</span>
        @endif
        <span>{{ trans_choice('carpool.web.seats_requested', $item['seats'], ['count' => $item['seats']]) }}</span>
        <x-slot:actions>
            @if ($item['chat_id'] && $item['status'] === 'accepted')
                <x-button size="sm" :href="route('carpool.chat', $item['chat_id'])"><x-lucide name="message-circle" class="size-4" />{{ __('carpool.web.write_to', ['name' => $item['offer']['driver']['name']]) }}</x-button>
            @elseif ($item['chat_id'])
                <x-button size="sm" variant="secondary" :href="route('carpool.chat', $item['chat_id'])">{{ __('carpool.open_chat') }}</x-button>
            @endif
            <x-button size="sm" variant="secondary" :href="$item['url']">{{ __('carpool.web.open_request') }}</x-button>
        </x-slot:actions>
    </x-service-event-row>
</x-card>
