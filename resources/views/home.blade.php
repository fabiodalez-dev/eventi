@php
    /*
     * L'ordine delle sezioni è quello vincolante di §11.2:
     *
     *   1. intestazione (nel layout)     7. in evidenza
     *   2. in corso adesso  ┐ Livewire   8. questo weekend
     *   3. inizia tra poco  ┘ in differita 9. griglia per categoria
     *   4. stasera                      10. locali attivi
     *   5. i prossimi giorni            11. doppia chiamata all'azione
     *   6. oggi durante il giorno
     *
     * Il contesto passato alla card è la finestra di EventOccurrenceQuery da
     * cui le occorrenze arrivano, così che il badge dica la verità senza
     * ricalcolare nulla (§8, D23).
     */
    $sectionsBefore = [
        'tonight' => ['title' => __('events.sections.tonight'), 'tone' => 'brand', 'context' => 'tonight', 'route' => 'events.today'],
    ];

    /*
     * L'immagine più grande sopra la piega è la prima locandina della prima
     * sezione disegnata: è quella da annunciare al browser prima che scopra
     * l'HTML che la contiene (§11.11). Se non c'è nessuna sezione — e le
     * sezioni vuote non si disegnano (§8.6) — non c'è niente da annunciare.
     */
    $firstSection = collect($sectionsBefore)->keys()->first(fn (string $key): bool => isset($sections[$key]));
    $lcp = $firstSection === null
        ? null
        : \App\Support\Poster::imageSet($sections[$firstSection]->first()->event)
            ?->withSizes('(min-width: 1024px) 280px, (min-width: 640px) 45vw, 90vw');

    $sectionsAfter = [
        'today' => ['title' => __('events.sections.today'), 'tone' => 'brand', 'context' => 'today', 'route' => 'events.today'],
        'featured' => ['title' => __('events.sections.featured'), 'tone' => 'accent', 'context' => 'upcoming', 'route' => 'events.index'],
        'weekend' => ['title' => __('events.sections.weekend'), 'tone' => 'accent', 'context' => 'upcoming', 'route' => 'events.weekend'],
    ];
@endphp

<x-layouts.app
    :description="$city?->name ? __('ui.header.tagline', ['city' => $city->name]) : null"
    :preload="$lcp"
>
    <x-slot:head>
        <x-json-ld :data="$structuredData" />
    </x-slot:head>

    @if ($city !== null)
        <h1 class="text-balance text-hero text-ink">{{ __('ui.header.tagline', ['city' => $city->name]) }}</h1>
    @endif

    {{-- "In corso adesso" e "Inizia tra poco": frammento a parte, caricato dopo
         il primo disegno della pagina. Sono le sole sezioni che cambiano di
         minuto in minuto, ed è per questo che §12.3 le vuole fuori dalla
         pagina che andrà in cache. --}}
    <livewire:live-now lazy />

    @foreach ($sectionsBefore as $key => $meta)
        @if (isset($sections[$key]))
            <section class="mt-section" aria-labelledby="sezione-{{ $key }}">
                <x-section-heading
                    :id="'sezione-'.$key"
                    :title="$meta['title']"
                    :tone="$meta['tone']"
                    :href="route($meta['route'])"
                />

                <x-event-grid :occurrences="$sections[$key]" :context="$meta['context']" :eager="true" />
            </section>
        @endif
    @endforeach

    @if ($days !== [])
        <section class="mt-section" aria-labelledby="sezione-giorni">
            <x-section-heading
                id="sezione-giorni"
                :title="__('events.sections.next_days')"
                tone="neutral"
                :href="route('events.index')"
            />

            <x-day-scroller :days="$days" :label="__('events.sections.next_days')" />
        </section>
    @endif

    @foreach ($sectionsAfter as $key => $meta)
        @if (isset($sections[$key]))
            <section class="mt-section" aria-labelledby="sezione-{{ $key }}">
                <x-section-heading
                    :id="'sezione-'.$key"
                    :title="$meta['title']"
                    :tone="$meta['tone']"
                    :href="route($meta['route'])"
                />

                <x-event-grid :occurrences="$sections[$key]" :context="$meta['context']" />
            </section>
        @endif
    @endforeach

    @if ($categories !== [])
        <section class="mt-section" aria-labelledby="sezione-categorie">
            <x-section-heading
                id="sezione-categorie"
                :title="__('events.sections.by_category')"
                tone="neutral"
            />

            <x-category-grid :categories="$categories" />
        </section>
    @endif

    @if ($venues->isNotEmpty())
        <section class="mt-section" aria-labelledby="sezione-locali">
            <x-section-heading
                id="sezione-locali"
                :title="__('venues.sections.active')"
                tone="neutral"
                :href="route('venues.index')"
            />

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($venues as $venue)
                    <x-venue-card :venue="$venue" />
                @endforeach
            </div>
        </section>
    @endif

    <section class="mt-section grid gap-4 sm:grid-cols-2" aria-label="{{ __('ui.cta.label') }}">
        <a
            href="{{ route('venue-applications.create') }}"
            class="flex flex-col justify-between gap-4 rounded-card bg-brand p-6 text-on-brand transition hover:bg-brand-strong"
        >
            <span class="text-section">{{ __('venues.claim.title') }}</span>
            <span class="text-sm opacity-90">{{ __('venues.claim.lead') }}</span>
        </a>

        <a
            href="{{ route('submissions.create') }}"
            class="flex flex-col justify-between gap-4 rounded-card bg-surface p-6 ring-1 ring-line transition hover:ring-line-strong"
        >
            <span class="text-section text-ink">{{ __('events.submit.title') }}</span>
            <span class="text-sm text-ink-muted">{{ __('events.submit.lead') }}</span>
        </a>
    </section>
</x-layouts.app>
