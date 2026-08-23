{{--
    La ricerca del sito (`/cerca`).

    I risultati sono raggruppati per tipo: chi scrive "jazz" può cercare una
    serata, il circolo che la organizza o l'etichetta con cui trovarle tutte.
    Un gruppo senza risultati non si disegna (§8.6); quando non c'è proprio
    nulla la pagina non resta spoglia ma propone le strade che portano da
    qualche parte.
--}}
<x-layouts.app :meta="$meta">
    <header class="flex flex-col gap-2">
        <h1 class="text-balance text-hero text-ink">{{ $meta->heading }}</h1>

        @if ($meta->description)
            <p class="max-w-prose text-sm text-ink-muted">{{ $meta->description }}</p>
        @endif
    </header>

    <form method="GET" action="{{ route('search') }}" role="search" class="mt-6 flex flex-wrap items-center gap-2">
        <label for="ricerca" class="sr-only">{{ __('search.label') }}</label>

        <input
            id="ricerca"
            type="search"
            name="q"
            value="{{ $term }}"
            autofocus
            placeholder="{{ __('search.placeholder') }}"
            class="min-w-0 flex-1 rounded-pill border border-line bg-surface px-4 py-2.5 text-ink placeholder:text-ink-subtle focus:border-brand focus:outline-none"
        >

        <button
            type="submit"
            class="rounded-pill bg-brand px-5 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
        >
            {{ __('search.submit') }}
        </button>
    </form>

    @if ($events !== null && $events->isNotEmpty())
        <section class="mt-section" aria-labelledby="risultati-eventi">
            <x-section-heading
                id="risultati-eventi"
                :title="__('search.groups.events')"
                tone="brand"
                :href="route('events.index', ['q' => $term])"
                :link-label="__('search.all_events', ['query' => $term])"
            />

            <x-event-grid :occurrences="$events" :eager="true" />
        </section>
    @endif

    @if ($venues !== null && $venues->isNotEmpty())
        <section class="mt-section" aria-labelledby="risultati-locali">
            <x-section-heading
                id="risultati-locali"
                :title="__('search.groups.venues')"
                tone="neutral"
                :href="route('venues.index', ['q' => $term])"
                :link-label="__('search.all_venues', ['query' => $term])"
            />

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($venues as $venue)
                    <x-venue-card :venue="$venue" />
                @endforeach
            </div>
        </section>
    @endif

    @if ($tags !== null && $tags->isNotEmpty())
        <section class="mt-section" aria-labelledby="risultati-tag">
            <x-section-heading id="risultati-tag" :title="__('search.groups.tags')" tone="neutral" />

            <div class="flex flex-wrap gap-2">
                @foreach ($tags as $tag)
                    <x-filter-chip :href="route('events.tag', ['tag' => $tag->slug])">
                        {{ $tag->name }}
                    </x-filter-chip>
                @endforeach
            </div>
        </section>
    @endif

    @if ($found === 0)
        <section class="mt-8 rounded-card bg-surface p-6 ring-1 ring-line" aria-labelledby="suggerimenti">
            <h2 id="suggerimenti" class="text-section text-ink">
                {{ $term === '' ? __('search.empty.prompt_title') : __('search.empty.title', ['query' => $term]) }}
            </h2>

            <p class="mt-1 max-w-prose text-sm text-ink-muted">
                {{ $term === '' ? __('search.empty.prompt_body') : __('search.empty.body') }}
            </p>

            <h3 class="mt-5 text-eyebrow text-ink-subtle uppercase">{{ __('search.empty.windows') }}</h3>

            <div class="mt-2 flex flex-wrap gap-2">
                <x-filter-chip :href="route('events.today')">{{ __('ui.nav.today') }}</x-filter-chip>
                <x-filter-chip :href="route('events.weekend')">{{ __('ui.nav.weekend') }}</x-filter-chip>
                <x-filter-chip :href="route('events.free')">{{ __('ui.nav.free') }}</x-filter-chip>
                <x-filter-chip :href="route('map.index')">{{ __('ui.nav.map') }}</x-filter-chip>
            </div>

            @if (($suggestions['categories'] ?? []) !== [])
                <h3 class="mt-5 text-eyebrow text-ink-subtle uppercase">{{ __('search.empty.categories') }}</h3>

                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($suggestions['categories'] as $category)
                        <x-filter-chip :href="$category['url']" :count="$category['count']">
                            {{ $category['name'] }}
                        </x-filter-chip>
                    @endforeach
                </div>
            @endif

            @if (($suggestions['tags'] ?? []) !== [])
                <h3 class="mt-5 text-eyebrow text-ink-subtle uppercase">{{ __('search.empty.tags') }}</h3>

                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($suggestions['tags'] as $tag)
                        <x-filter-chip :href="$tag['url']">{{ $tag['name'] }}</x-filter-chip>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</x-layouts.app>
