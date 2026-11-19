{{--
    La mappa con il segnaposto trascinabile.

    Il campo non ha uno stato suo: scrive nei due campi delle coordinate, che
    restano nel modulo e restano modificabili a mano. Chi trascina vede
    cambiare i numeri, chi scrive i numeri vede spostarsi il segnaposto: sono
    due viste sullo stesso dato, e nessuna delle due è la copia dell'altra.

    Sotto la mappa una riga dice se il punto c'è e a quale indirizzo
    corrisponde («Punto salvato: Via Roma 1, Padova»), oppure che manca. I
    numeri delle coordinate si vedono solo se chi compone il modulo lo chiede
    (`showCoordinates()`).
--}}
@php
    $lat = $getLatField();
    $lng = $getLngField();
    $indirizzo = $getAddressField();
    $comune = $getMunicipalityField();
    $percorso = $getStatePath();
    $prefisso = str($percorso)->beforeLast('.')->toString();
    $chiave = fn (string $campo): string => $prefisso === $percorso ? $campo : $prefisso.'.'.$campo;
    $citta = \App\Models\City::query()->orderBy('id')->first();
    $mostraCoordinate = $shouldShowCoordinates();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="mappaSegnaposto({
            lat: $wire.get(@js($chiave($lat))) ?? null,
            lng: $wire.get(@js($chiave($lng))) ?? null,
            fallbackLat: @js((float) ($citta?->center_lat ?? 45.4064)),
            fallbackLng: @js((float) ($citta?->center_lng ?? 11.8768)),
            tiles: @js(config('map.tiles_url_light')),
            attribution: @js(config('map.attribution')),
            statePathLat: @js($chiave($lat)),
            statePathLng: @js($chiave($lng)),
        })"
        {{-- Il segnale arriva da fuori: il pulsante «trova sulla mappa»
             scrive le coordinate da PHP, e la mappa — che i campi li scrive,
             non li legge — resterebbe indietro. Si confronta il **nome** del
             campo e non il percorso di stato, perche' il percorso cambia a
             seconda di dove il modulo e' innestato mentre il nome no. --}}
        x-on:mappa-vai-a.window="if ($event.detail.campo === @js($getName())) vaiA($event.detail.lat, $event.detail.lng)"
        class="fi-map-picker"
    >
        <div x-ref="mappa" class="fi-map-picker-canvas"></div>

        <p class="fi-map-picker-hint">{{ __('admin.map.hint') }}</p>

        {{-- L'indirizzo si legge dai campi del modulo mentre si scrive:
             `$wire` è reattivo, quindi la riga segue quello che c'è adesso. --}}
        <p
            class="fi-map-picker-status"
            role="status"
            x-data="{
                get indirizzo() {
                    return [@js($indirizzo ? $chiave($indirizzo) : null), @js($comune ? $chiave($comune) : null)]
                        .filter((campo) => campo)
                        .map((campo) => ($wire.get(campo) ?? '').toString().trim())
                        .filter((valore) => valore !== '')
                        .join(', ')
                },
            }"
        >
            <span x-show="lat !== null" x-cloak>
                <x-filament::icon icon="heroicon-m-map-pin" class="fi-map-picker-status-icon" />
                <span x-show="indirizzo !== ''">{{ __('pannelli.map.saved') }} <strong x-text="indirizzo"></strong></span>
                <span x-show="indirizzo === ''">{{ __('pannelli.map.saved_no_address') }}</span>
                @if ($mostraCoordinate)
                    <span class="fi-map-picker-coordinates">(<span x-text="lat"></span>, <span x-text="lng"></span>)</span>
                @endif
            </span>
            <span x-show="lat === null" x-cloak>{{ __('pannelli.map.missing') }}</span>
        </p>
    </div>
</x-dynamic-component>
