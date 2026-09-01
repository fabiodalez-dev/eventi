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
])

@php
    $radii = config('eventi.distance_options');
    $target = $action ?? route('events.index');
    $current = $filters->radius === null ? null : (int) $filters->radius;
    $default = $radii[1] ?? $radii[0];

    $urlFor = static fn (int $km): string => $target.'?'.http_build_query(
        $filters->withPosition($filters->lat, $filters->lng, (float) $km)->toQueryString()
    );
@endphp

<section {{ $attributes->class(['bg-canvas p-4 border-2 border-line']) }} aria-labelledby="vicino-a-me">
    <h2 id="vicino-a-me" class="text-card text-ink">{{ __('map.near.title') }}</h2>

    @if ($filters->hasPosition())
        <p class="mt-1 text-sm text-ink-muted">
            {{ __('map.near.active', ['km' => $current ?? $default]) }}
        </p>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            @foreach ($radii as $km)
                <x-filter-chip :href="$urlFor((int) $km)" :active="$current === (int) $km">
                    {{ __('map.near.radius', ['km' => $km]) }}
                </x-filter-chip>
            @endforeach

            <a
                href="{{ $target.'?'.http_build_query($filters->withPosition(null, null, null)->toQueryString()) }}"
                class="text-sm font-semibold text-ink-muted underline hover:text-ink"
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
                data-geolocate-url="{{ $target }}?{{ http_build_query($filters->toQueryString()) }}"
                data-geolocate-radius="{{ $current ?? $default }}"
                data-geolocate-denied="{{ __('map.near.denied') }}"
                class="hidden bg-brand px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-brand uppercase transition hover:bg-brand-strong"
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
    @endif
</section>
