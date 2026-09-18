<x-layouts.app :meta="$meta">
    <x-sponsorship-banner :city="$city" />
    <header id="feed-events" class="flex flex-col gap-2">
        <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
        <p class="text-sm text-ink-muted">{{ __('account.feed.lead') }}</p>
    </header>

    <details id="feed-interests" class="mt-6 border-y-2 border-line py-4" open>
        <summary class="cursor-pointer font-display text-lg font-extrabold">{{ $hasFollows ? 'Gestisci locali e categorie seguiti' : __('account.feed.onboarding_title') }}</summary>
        <p class="mt-3 text-sm text-ink-muted">Puoi aggiungere o rimuovere tutte le fonti che vuoi. Usa «Segui», poi aggiorna il feed per vedere i nuovi eventi.</p>
        <div class="mt-5 grid gap-8 lg:grid-cols-2">
            <section aria-labelledby="feed-venues">
                <h2 id="feed-venues" class="text-eyebrow uppercase">Locali da seguire</h2>
                <form method="get" action="{{ route('account.feed') }}#feed-interests" class="my-4 flex flex-wrap gap-2">
                    <label for="feed-venue-search" class="sr-only">Cerca un locale</label>
                    <input id="feed-venue-search" name="venue_q" value="{{ $venueSearch }}" type="search" placeholder="Cerca un locale" maxlength="120" class="min-w-0 flex-1 border-2 border-line bg-canvas px-3 py-2">
                    <x-button type="submit">Cerca</x-button>
                    @if ($venueSearch !== '')<a class="p-2 underline" href="{{ route('account.feed', ['venues_page' => 1]) }}#feed-interests">Tutti i locali</a>@endif
                </form>
                <ul class="space-y-2" data-feed-venues>
                    @forelse ($venues as $venue)
                        <li class="flex flex-wrap items-center justify-between gap-3 border-b border-line py-3">
                            <a class="min-w-0 break-words font-semibold" href="{{ route('venues.show', $venue) }}">{{ $venue->name }}</a>
                            <x-follow-button type="venue" :id="$venue->getKey()" />
                        </li>
                    @empty
                        <li class="py-4 text-ink-muted">Nessun locale trovato. Prova un altro nome.</li>
                    @endforelse
                </ul>
                <x-pagination :paginator="$venues" :summary="true" label="Paginazione dei locali" />
            </section>
            <section aria-labelledby="feed-categories">
                <h2 id="feed-categories" class="text-eyebrow uppercase">Categorie da seguire</h2>
                <ul class="mt-4 space-y-2" data-feed-categories>
                    @foreach ($categories as $category)
                        <li class="flex flex-wrap items-center justify-between gap-3 border-b border-line py-3">
                            <a class="min-w-0 break-words font-semibold" href="{{ route('events.category', $category) }}">{{ $category->name }}</a>
                            <x-follow-button type="category" :id="$category->getKey()" />
                        </li>
                    @endforeach
                </ul>
                <x-pagination :paginator="$categories" :summary="true" label="Paginazione delle categorie" />
            </section>
        </div>
        <a class="ui-action mt-6 inline-block border-2 border-line px-4 py-3 font-bold" href="{{ route('account.feed') }}#feed-events">Aggiorna il feed</a>
    </details>

    @if ($occurrences !== null && $occurrences->total() > 0)
        {{-- Explicit pagination: do not opt this feed into infinite scroll. --}}
        <div class="mt-6" data-feed-results>
            <x-event-grid :occurrences="$occurrences->getCollection()" :adaptive="false" />
        </div>
        <div class="mt-8" data-feed-pagination>
            <x-pagination :paginator="$occurrences" :summary="true" label="Paginazione degli eventi del feed" />
        </div>
    @else
        <p class="mt-8 text-ink-muted">{{ $hasFollows ? 'Non ci sono ancora nuove date dalle fonti che segui. Puoi aggiungere altri locali o categorie qui sopra.' : __('account.feed.onboarding_lead') }}</p>
    @endif
</x-layouts.app>
