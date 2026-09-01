{{--
    La mappa di un locale e i collegamenti per arrivarci (§11.5, §11.9).

    **Perché non è più un `iframe` di openstreetmap.org.** Lo era: bastava un
    indirizzo e nessuno script. Ma dentro quel riquadro entra il sito intero —
    la sua barra, i suoi controlli, «Report a problem», «Make a Donation» — e
    soprattutto entra la sua tavolozza: una mappa chiara e colorata in mezzo a
    una scheda nera. Il foglio di stile della pagina non può raggiungerla,
    perché un `iframe` è un documento a sé.

    Ora è lo stesso riquadro Leaflet di tutte le altre mappe del sito, in
    versione **ferma**: un punto, nessun trascinamento, nessuna rotella. Chi
    vuole muoversi ha i tre collegamenti qui sotto, che aprono l'applicazione
    di mappe che usa davvero.

    Le indicazioni sono tre, non una: su iPhone il collegamento di Google apre
    il browser, su Android quello di Apple non apre nulla.
--}}
@props([
    'venue',
    'title' => null,
])

@php
    /*
     * Un carico con un solo punto, nella stessa forma che usa la mappa grande:
     * liste posizionali `[locale, lng, lat, categoria, quante]`. Passare da qui
     * invece di inventare un secondo formato è ciò che permette a un solo
     * script di disegnare tutte le mappe del sito.
     */
    $payload = [
        'markers' => $venue->lat === null || $venue->lng === null
            ? []
            : [[$venue->getKey(), (float) $venue->lng, (float) $venue->lat, null, 1]],
        'categories' => [],
        'truncated' => false,
    ];
@endphp

<div {{ $attributes->class(['flex flex-col gap-3']) }}>
    @if ($payload['markers'] !== [])
        <x-events-map
            :city="$venue->city"
            :filters="new \App\DTOs\EventFilters"
            :payload="$payload"
            :static="true"
            :show-legend="false"
            :center="[(float) $venue->lng, (float) $venue->lat]"
            :zoom="config('map.venue_zoom')"
            map-class="aspect-[16/10] w-full"
        />
    @endif

    <div class="flex flex-wrap gap-0.5">
        <a
            href="{{ \App\Support\MapLinks::google($venue) }}"
            rel="noopener noreferrer"
            target="_blank"
            class="bg-accent px-3.5 py-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
        >
            {{ __('common.actions.directions_google') }}
        </a>

        <a
            href="{{ \App\Support\MapLinks::apple($venue) }}"
            rel="noopener noreferrer"
            target="_blank"
            class="border-2 border-line px-3.5 py-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:border-accent hover:text-accent"
        >
            {{ __('common.actions.directions_apple') }}
        </a>

        <a
            href="{{ \App\Support\MapLinks::openStreetMap($venue) }}"
            rel="noopener noreferrer"
            target="_blank"
            class="border-2 border-line px-3.5 py-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:border-accent hover:text-accent"
        >
            {{ __('common.actions.directions_osm') }}
        </a>
    </div>
</div>
