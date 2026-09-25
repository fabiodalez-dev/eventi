{{-- ----------------------------------------------------------------------
     «Vicino a te»: una sezione come le altre, non un riquadro a parte.

     Era l'unica della pagina iniziale a costruirsi la testata per conto suo —
     due `<section>` affiancate che dichiaravano lo stesso `aria-labelledby`,
     margini fissi invece di quelli a scala, e un occhiello scritto come testo
     semplice. Quest'ultimo non era una differenza di gusto: nel tema chiaro
     `.section-eyebrow` diventa `display: contents` e numero, titolo e frase si
     ridispongono per `order`. Senza gli involucri `section-index` e
     `section-note` quella ricomposizione non avviene, e questa testata restava
     com'era mentre tutte le altre si riordinavano.

     I comandi della posizione stanno dentro la sezione, separati dalla griglia
     dallo stesso filo da 2px che separa testata ed elenco in ogni altra
     sezione — e che il tema chiaro toglie da solo, come fa con le altre.

     Le classi sono quelle già usate altrove nella pagina, non valori nuovi:
     il foglio di stile si costruisce leggendo i modelli, e un valore
     arbitrario introdotto qui esisterebbe solo dopo una ricompilazione.
---------------------------------------------------------------------- --}}
<div data-home-nearby>
    <section class="home-section border-b-2 border-line" aria-labelledby="sezione-vicino">
        <div class="flex flex-wrap items-end justify-between gap-5 px-gutter pt-[clamp(1.5rem,2.8vw,2.75rem)] pb-[clamp(1.125rem,2vw,1.625rem)]">
            <div class="section-head flex flex-col gap-2">
                <span class="section-eyebrow font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-accent uppercase"><span class="section-index">{{ str_pad((string) $numero, 2, '0', STR_PAD_LEFT) }}</span><span class="section-note"><span class="section-dash"> {{ __('common.dash') }} </span>{{ __('events.sections.nearby_eyebrow') }}</span></span>
                <h2 id="sezione-vicino" class="m-0 scroll-mt-32 font-display text-[clamp(1.875rem,4vw,4rem)] leading-[0.94] font-extrabold tracking-[-0.04em] uppercase reveal-left">
                    {{ __('events.sections.nearby') }}
                </h2>
            </div>
        </div>

        {{-- I comandi della posizione. `data-remembered-location` sta qui e non
             più sulla sezione: al codice serve un solo elemento che contenga i
             due pulsanti, il raggio e la riga di stato, e tenerlo sul
             contenitore dei comandi lascia la sezione libera di comportarsi
             come le altre. --}}
        <div
            class="border-t-2 border-line px-gutter py-6"
            data-remembered-location
            data-endpoint="{{ route('location.store') }}"
            data-position="{{ isset($nearbyPosition) ? $nearbyPosition['lat'].','.$nearbyPosition['lng'] : '' }}"
            data-saved-at="{{ $nearbyPosition['saved_at'] ?? '' }}" data-authenticated="{{ auth()->check() ? '1' : '0' }}"
            data-loading="{{ __('location.loading') }}" data-unavailable="{{ __('location.unavailable') }}"
            data-failed="{{ __('location.failed') }}" data-saved="{{ __('location.saved') }}"
        >
            <p class="m-0 max-w-[52ch] text-sm leading-[1.55] text-ink-muted">{{ __('location.help') }}</p>

            <div class="mt-4 flex flex-wrap items-center gap-2.5">
                <label class="inline-flex items-center gap-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-ink-muted uppercase">
                    {{ __('location.radius') }}
                    <select data-nearby-radius class="h-[52px] border-2 border-line bg-canvas px-4 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase">
                        @foreach ([5, 10] as $km)
                            <option value="{{ $km }}" @selected(($nearbyRadius ?? 5) === $km)>{{ __('location.radius_km', ['km' => $km]) }}</option>
                        @endforeach
                    </select>
                </label>

                <button
                    type="button"
                    data-location-use
                    class="ui-action inline-flex h-[52px] items-center gap-2 bg-accent px-[22px] font-display text-xs leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
                >
                    {{ __('location.remember') }}
                </button>

                <button
                    type="button"
                    data-location-forget
                    @if (! isset($nearbyPosition)) hidden @endif
                    class="ui-action inline-flex h-[52px] items-center border-2 border-ink px-[22px] font-display text-xs leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:bg-ink hover:text-ink-inverted"
                >
                    {{ __('location.forget') }}
                </button>
            </div>

            <p role="status" class="mt-3 text-sm text-ink-muted">
                {{ isset($nearbyPosition) ? __('location.saved') : __('location.default') }}
            </p>
        </div>

        @if ($nearby->isNotEmpty() && \Illuminate\Support\Facades\Route::has('map.index'))
            {{-- Il rinvio sta sulla griglia e non sulla sezione: la mappa è la
                 parte pesante, i comandi sopra devono restare disegnati. --}}
            <div class="home-nearby defer-offscreen grid gap-0.5 border-t-2 border-line bg-line [grid-template-columns:repeat(auto-fit,minmax(min(340px,100%),1fr))]">
                <div class="flex flex-col bg-canvas">
                    @foreach ($nearby as $occorrenza)
                        <a
                            href="{{ \App\Support\EventUrl::occurrence($occorrenza) }}"
                            class="group flex items-center gap-3.5 border-b-2 border-line px-[clamp(1rem,1.6vw,1.25rem)] py-3.5 transition-colors hover:bg-accent/[0.055]"
                        >
                            <span class="min-w-14 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.1em] text-accent uppercase">
                                {{ $occorrenza->is_all_day ? __('events.badge.all_day') : $formatter->time($occorrenza->starts_at) }}
                            </span>
                            <span class="flex min-w-0 flex-auto flex-col gap-1">
                                <span class="font-display text-[clamp(0.938rem,1.25vw,1.188rem)] leading-[1.1] font-extrabold tracking-[-0.02em] uppercase">{{ $occorrenza->event->title }}</span>
                                <span class="truncate text-xs leading-[1.4] text-ink-subtle">
                                    {{ collect([$occorrenza->effectiveVenue()?->name, $occorrenza->effectiveVenue()?->zone ?: $occorrenza->effectiveVenue()?->municipality])->filter()->implode(' '.__('common.separator').' ') }}
                                </span>
                            </span>
                            <span class="font-display text-[0.813rem] leading-none font-extrabold whitespace-nowrap">
                                <x-price-tag :event="$occorrenza->event" />
                            </span>
                        </a>
                    @endforeach

                    <div class="mt-auto p-[18px]">
                        <a
                            href="{{ route('map.index') }}"
                            class="ui-action flex h-[46px] w-full items-center bg-accent px-4 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
                        >
                            {{ __('ui.hero.open_map_full') }}
                        </a>
                    </div>
                </div>

                <div class="relative min-h-[clamp(25rem,44vw,35rem)] bg-canvas">
                    <x-events-map
                        :city="$city"
                        :filters="$mapFilters"
                        :payload="$mapPayload"
                        :show-legend="false"
                        class="absolute inset-0"
                        map-class="h-full w-full"
                    />
                </div>
            </div>
        @endif
    </section>
</div>
