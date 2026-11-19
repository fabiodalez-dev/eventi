{{--
    La testata evento delle pagine dei passaggi: locandina, titolo, data e
    sede, come in tutti gli elenchi di servizio. `$occurrence` è il modello
    (caricato dal controller con locandina e sede); se manca resta il titolo
    salvato nel passaggio. `$offer` (facoltativo) aggiunge i collegamenti
    all'evento e alla zona di partenza: tratta, zona e orario sono già nella
    testata della pagina.
--}}
@php
    $occurrence ??= null;
    $offerData = $offer ?? null;
    $eventTitle = $offerData['event_title'] ?? $occurrence?->event?->title ?? '';
@endphp
<x-card padding="sm" data-carpool-event>
    @if ($occurrence)
        <x-service-event-row :occurrence="$occurrence" :title="$eventTitle">
            @if ($offerData && (filled($offerData['event_url']) || filled($offerData['map_url'])))
                <x-slot:actions>
                    @if (filled($offerData['event_url']))
                        <x-button variant="secondary" size="sm" :href="$offerData['event_url']">{{ __('carpool.event') }}</x-button>
                    @endif
                    @if (filled($offerData['map_url']))
                        <x-button variant="ghost" size="sm" :href="$offerData['map_url']" target="_blank" rel="noopener">
                            <x-lucide name="map-pin" class="size-4" />{{ __('carpool.map') }}
                        </x-button>
                    @endif
                </x-slot:actions>
            @endif
        </x-service-event-row>
    @else
        <x-service-event-row :title="$eventTitle" :meta="$offerData['event_location'] ?? null" :href="$offerData['event_url'] ?? false" />
    @endif
</x-card>
