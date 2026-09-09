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

        <div class="mt-3 flex flex-wrap items-center gap-2">
            @foreach ($radii as $km)
                @continue($current !== (int) $km && $counts !== null && ($counts['radius'][(string) $km] ?? 0) === 0)
                <x-filter-chip data-filter-link :href="$urlFor($current === (int) $km ? null : (int) $km)" :active="$current === (int) $km">
                    {{ __('map.near.radius', ['km' => $km]) }}
                </x-filter-chip>
            @endforeach

            <a
                data-filter-link
                href="{{ $urlFor(null) }}"
                class="inline-flex min-h-12 items-center text-base font-semibold text-ink underline hover:text-accent"
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

            <label class="flex items-center gap-2 text-sm text-ink-muted">
                <span>{{ __('map.near.radius_label') }}</span>

                {{-- Il raggio si sceglie **prima** di concedere la posizione:
                     il pulsante lo legge da qui. --}}
                <select
                    data-geolocate-radius-input
                    class="border border-line bg-surface px-3 py-1.5 text-sm text-ink focus:border-brand focus:outline-none"
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
