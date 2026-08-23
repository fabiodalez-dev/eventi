{{--
    La lista degli eventi (§11.3).

    Tutto lo stato della pagina sta nell'indirizzo: i filtri sono link, la
    paginazione è `?page=`, e l'infinite scroll è solo un miglioramento che il
    JavaScript aggiunge sopra a una paginazione che funziona da sola. Chi
    naviga senza JavaScript ha una pagina completa, non una versione ridotta.
--}}
<x-layouts.app :meta="$meta">
    <x-slot:head>
        <x-json-ld :data="$structuredData" />
    </x-slot:head>

    <header class="flex flex-col gap-2">
        <h1 class="text-balance text-hero text-ink">{{ $meta->heading }}</h1>

        @if ($meta->description)
            <p class="max-w-prose text-sm text-ink-muted">{{ $meta->description }}</p>
        @endif
    </header>

    <div class="mt-6">
        <x-filter-bar
            :filters="$filters"
            :categories="$categories"
            :tags="$tags"
            :municipalities="$municipalities"
            :venues="$venues"
            :total="$occurrences->total()"
        />
    </div>

    {{-- "Vicino a me" (§11.7): la posizione si chiede qui, con la frase che
         dice perché, e non all'apertura del sito. --}}
    <div class="mt-4">
        <x-near-me :filters="$filters" :action="route('events.index')" />
    </div>

    @if ($occurrences->total() > 0)
        {{-- Il contenitore dei risultati è ciò che l'infinite scroll estende:
             l'attributo lo dichiara, e senza JavaScript non fa niente. --}}
        <div class="mt-8" data-results>
            <x-event-grid :occurrences="$occurrences->getCollection()" :eager="true" />
        </div>

        <div data-pagination>
            <x-pagination :paginator="$occurrences" :summary="true" />
        </div>

        {{-- Il feed porta con sé i filtri accesi: si sottoscrive esattamente la
             lista che si sta guardando (§11.10). --}}
        <div class="mt-section">
            <x-feed-links :filters="$filters" />
        </div>
    @else
        <div class="mt-8">
            {{-- Questo stato vuoto è legittimo: qualcuno ha chiesto qualcosa di
                 preciso e ha diritto a una risposta, che è diverso dal
                 disegnare una sezione vuota in homepage (§8.6). --}}
            <x-empty-state
                :title="__('events.empty.search_title')"
                :description="__('events.empty.search_body')"
            >
                <a
                    href="{{ route('events.index') }}"
                    class="rounded-pill bg-brand px-4 py-2 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
                >
                    {{ __('events.redirects.to_all') }}
                </a>

                <a
                    href="{{ route('events.weekend') }}"
                    class="rounded-pill bg-surface px-4 py-2 text-sm font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
                >
                    {{ __('events.redirects.to_weekend') }}
                </a>
            </x-empty-state>
        </div>
    @endif
</x-layouts.app>
