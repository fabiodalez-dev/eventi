<x-layouts.app :meta="$meta" :wide="true">
    <div data-event-browser data-result-count="{{ $occurrences->total() }}">
    @if ($filters->discovery)
        <p class="border-b-2 border-line p-gutter text-ink-muted">{{ __('tonight.filtered') }}</p>
    @endif
    @if (($taxonomy ?? null) && request()->integer('page', 1) === 1)
        <div class="px-gutter"><x-editorial-content :model="$taxonomy" /></div>
    @endif
    <x-slot:head>
        <x-json-ld :data="$structuredData" />
        @vite('resources/js/map.js')
    </x-slot:head>

    <div class="grid items-start gap-0.5 bg-line lg:[grid-template-columns:minmax(232px,268px)_minmax(0,1.32fr)_minmax(0,1fr)]">
        <div class="catalog-heading-panel bg-canvas lg:col-start-2 lg:row-start-1 flex flex-col gap-4 border-b-2 border-line px-[clamp(1rem,1.8vw,1.625rem)] py-[clamp(1.125rem,2.2vw,1.875rem)]">
            <div class="flex items-center gap-2.5">
                <span aria-hidden="true" class="size-2 bg-accent blink-dot"></span>
                <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-ink-muted uppercase">
                    {{ trans_choice('filters.results', $occurrences->total(), ['count' => $occurrences->total()]) }}
                </span>
            </div>

            <h1 class="m-0 font-display text-[clamp(1.875rem,3.6vw,3.625rem)] leading-[0.94] font-extrabold tracking-[-0.04em] text-balance uppercase">
                {{ $meta->heading }}
            </h1>

            @if ($meta->description)
                <p class="m-0 max-w-prose text-[0.813rem] leading-[1.5] text-ink-muted">{{ $meta->description }}</p>
            @endif

        </div>

        <aside
            id="filtri"
            aria-label="{{ __('filters.panel_label') }}"
            class="scroll-mt-header flex flex-col gap-6 overflow-y-auto bg-canvas p-[clamp(1rem,1.6vw,1.375rem)] lg:sticky lg:top-header lg:col-start-1 lg:row-start-1 lg:row-span-2 lg:max-h-below-header"
        >
            <details data-catalog-filters>
                <summary data-filter-jump class="ui-action min-h-12 cursor-pointer py-3 font-semibold">{{ __('filters.jump') }}</summary>
                <div class="mt-4 flex flex-col gap-6">
                    <x-filter-bar
                        :counts="$facetCounts"
                        :filters="$filters"
                        :categories="$categories"
                        :tags="$tags"
                        :municipalities="$municipalities"
                        :zones="$zones"
                        :venues="$venues"
                        :total="$occurrences->total()"
                    />

                    {{-- "Vicino a me" (§11.7): la posizione si chiede qui, con la frase
                         che dice perché, e non all'apertura del sito. --}}
                    <x-near-me :filters="$filters" :counts="$facetCounts" :action="route('events.index')" />
                    <button type="button" data-filter-close hidden class="ui-action min-h-12 bg-brand px-4 py-3 font-semibold text-on-brand">{{ __('filters.show_results') }}</button>
                </div>
            </details>
        </aside>

        <section class="bg-canvas lg:col-start-2 lg:row-start-2 lg:min-h-below-header">
            @if ($occurrences->total() > 0)
                {{-- Il contenitore dei risultati è ciò che l'infinite scroll
                     estende: l'attributo lo dichiara, e senza JavaScript non fa
                     niente. --}}
                {{-- La campagna in cima, quando c'è: sopra i risultati e fuori
                     dal contenitore che l'infinite scroll estende, altrimenti
                     ricomparirebbe a ogni pagina caricata. --}}
                @if ($sponsorship !== null && $sponsoredOccurrence !== null && ! $filters->discovery)
                    <x-sponsored-card
                        :sponsorship="$sponsorship"
                        :occurrence="$sponsoredOccurrence"
                        class="border-b-2 border-line"
                        level="h2"
                    />
                @endif

                <div data-results>
                    <x-event-grid
                        :occurrences="$occurrences->getCollection()"
                        :show-poster="true"
                        :offset="($occurrences->currentPage() - 1) * $occurrences->perPage()"
                    />
                </div>

                <div data-pagination>
                    <x-pagination :paginator="$occurrences" :summary="true" />
                </div>

                {{-- Il feed porta con sé i filtri accesi: si sottoscrive
                     esattamente la lista che si sta guardando (§11.10). --}}
                <div class="border-t-2 border-line px-[clamp(1rem,1.8vw,1.625rem)] py-6">
                    <x-feed-links :filters="$filters" />
                </div>
            @else
                {{-- Questo stato vuoto è legittimo: qualcuno ha chiesto qualcosa
                     di preciso e ha diritto a una risposta, che è diverso dal
                     disegnare una sezione vuota in homepage (§8.6). --}}
                <div class="flex flex-col items-start gap-[18px] px-[clamp(1rem,1.8vw,1.625rem)] py-[clamp(2.5rem,6vw,5.625rem)]">
                    <span class="font-display text-[clamp(1.875rem,3.4vw,3.25rem)] leading-none font-extrabold tracking-[-0.04em] uppercase">
                        {{ __('events.empty.search_title') }}
                    </span>

                    <p class="m-0 max-w-[40ch] text-[0.938rem] leading-[1.55] text-ink-muted">
                        {{ __('events.empty.search_body') }}
                    </p>

                    <div class="flex flex-wrap gap-0.5">
                        <a
                            href="{{ route('events.index') }}"
                            class="ui-action inline-flex h-[46px] items-center bg-accent px-[18px] font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
                        >
                            {{ __('events.redirects.to_all') }}
                        </a>

                        <a
                            href="{{ route('events.weekend') }}"
                            class="ui-action inline-flex h-[46px] items-center border-2 border-line px-[18px] font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:border-accent hover:text-accent"
                        >
                            {{ __('events.redirects.to_weekend') }}
                        </a>
                    </div>
                </div>
            @endif
        </section>

        {{-- La terza colonna: gli stessi risultati, visti da sopra. Segue lo
             scorrimento perché serve mentre si guarda l'elenco, non dopo. --}}
        <section class="flex flex-col bg-canvas lg:col-start-3 lg:row-start-1 lg:row-span-2 lg:sticky lg:top-header lg:h-below-header" aria-label="{{ __('map.label') }}">
            <div class="flex items-center justify-between gap-2.5 border-b-2 border-line px-4 py-3.5">
                <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.16em] uppercase">
                    {{ trans_choice('map.pins', count($mapPayload['markers']), ['count' => count($mapPayload['markers'])]) }}
                </span>
            </div>

            <x-events-map
                :city="$city"
                :filters="$filters"
                :payload="$mapPayload"
                :show-legend="false"
                class="min-h-[320px] flex-auto"
                map-class="h-full min-h-[320px] w-full"
            />
        </section>
    </div>
    </div>
</x-layouts.app>
