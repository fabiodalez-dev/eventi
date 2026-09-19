{{--
    "Vicino a me" (§11.7).

    Tre regole, e nessuna è negoziabile:

    1. **La posizione non si chiede all'apertura del sito.** Il browser mostra
       il proprio avviso solo quando qualcuno preme il pulsante, e questo
       riquadro dice prima *perché* la si chiede.
    2. **La posizione non viene salvata.** Finisce nella query string della
       ricerca in corso — dove chi legge la vede — e sparisce con essa: non
       tocca il database, non entra in un cookie, non finisce in un registro.
    3. **Senza posizione la pagina funziona lo stesso.** Il pulsante compare
       solo se il browser sa geolocalizzare (lo scopre il JavaScript); i raggi
       restano link normali per chi la posizione l'ha già concessa.
--}}
@props([
    'filters',
    /* Dove riportare chi concede la posizione: la lista o la mappa */
    'action' => null,
    'counts' => null,
])

@php
    $radii = config('eventi.distance_options');
    $target = $action ?? route('events.index');
    $current = $filters->hasPosition() ? (int) ($filters->radius ?? $radii[0]) : null;
    $default = $radii[1] ?? $radii[0];

    $urlFor = static function (?int $km) use ($target, $filters): string {
        $set = $filters->withPosition($km === null ? null : $filters->lat, $km === null ? null : $filters->lng, $km === null ? null : (float) $km);
        $query = $set->toQueryString();
        if (request()->boolean('all_dates') && ! $set->hasDateWindow()) $query['all_dates'] = '1';
        return $target.'?'.http_build_query($query);
    };
@endphp

<section {{ $attributes->class(['bg-canvas p-4 border-2 border-line']) }} aria-labelledby="vicino-a-me">
    <h2 id="vicino-a-me" class="text-card text-ink">{{ __('map.near.title') }}</h2>

    @if ($filters->hasPosition())
        <p class="mt-1 text-sm text-ink-muted">
            {{ __('map.near.active', ['km' => $current ?? $default]) }}
        </p>

        <div class="mt-3 flex flex-wrap items-center gap-2 lg:flex-col lg:items-stretch">
            @foreach ($radii as $km)
                @continue($current !== (int) $km && $counts !== null && ($counts['radius'][(string) $km] ?? 0) === 0)
                <x-filter-chip data-filter-link :href="$urlFor($current === (int) $km ? null : (int) $km)" :active="$current === (int) $km" :count="$counts === null ? null : ($counts['radius'][(string) $km] ?? 0)">
                    {{ __('map.near.radius', ['km' => $km]) }}
                </x-filter-chip>
            @endforeach

            {{-- `basis-full`: sempre su una riga propria.

                 Le pastiglie sopra scelgono un raggio, questo collegamento
                 toglie la posizione: sono due cose diverse, e in coda alla
                 stessa riga sembrava la quarta scelta della serie. Dove ci
                 stava si accodava, dove non ci stava andava a capo — quindi
                 cambiava significato a seconda della larghezza della colonna.

                 Una riga propria lo separa sempre, invece che quando avanza
                 poco spazio. --}}
            <a
                data-filter-link
                href="{{ $urlFor(null) }}"
                class="inline-flex min-h-12 basis-full items-center text-base font-semibold text-ink underline hover:text-accent"
            >
                {{ __('map.near.clear') }}
            </a>
        </div>
    @else
        <p class="mt-1 max-w-prose text-sm text-ink-muted">{{ __('map.near.body') }}</p>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            {{-- Nasconde e mostra il JavaScript: senza geolocalizzazione il
                 pulsante non comparirebbe mai, e un pulsante che non fa niente
                 è peggio di un pulsante che non c'è. --}}
            <button
                type="button"
                data-geolocate
                data-geolocate-url="{{ $urlFor(null) }}"
                data-geolocate-radius="{{ $current ?? $default }}"
                data-geolocate-denied="{{ __('map.near.denied') }}"
                data-geolocate-loading="{{ __('map.near.loading') }}"
                data-geolocate-unavailable="{{ __('map.near.unavailable') }}"
                class="hidden min-h-12 bg-brand px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-brand uppercase transition hover:bg-brand-strong"
            >
                {{ __('map.near.allow') }}
            </button>

            {{-- Etichetta sopra, menu sotto. Affiancati stavano su una riga
                 sola finché la colonna era larga; in una barra dei filtri da
                 248px il menu si stringeva fino a nascondere la voce scelta —
                 «Entro 5 km» diventava «Entro…». Incolonnati, il menu ha
                 tutta la larghezza che gli serve a qualunque misura. --}}
            <label class="flex w-full flex-col items-start gap-1.5 text-sm text-ink-muted">
                <span>{{ __('map.near.radius_label') }}</span>

                {{-- Il raggio si sceglie **prima** di concedere la posizione:
                     il pulsante lo legge da qui. --}}
                <select
                    data-geolocate-radius-input
                    class="w-full max-w-xs min-h-12 border border-line bg-surface px-3 py-1.5 text-sm text-ink focus:border-brand focus:outline-none"
                >
                    @foreach ($radii as $km)
                        <option value="{{ $km }}" @selected((int) $km === ($current ?? $default))>
                            {{ __('map.near.radius', ['km' => $km]) }}
                        </option>
                    @endforeach
                </select>
            </label>
        </div>
        <p data-geolocate-status role="status" aria-live="polite" class="mt-2 text-sm text-ink-muted"></p>
    @endif
</section>
