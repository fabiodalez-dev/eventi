{{--
    `/il-mio-feed` (§15.7): le prossime date di ciò che si segue.

    **Mai una pagina vuota.** Chi non segue ancora niente non vede un elenco
    senza righe ma i locali più attivi e le categorie con più date in
    programma: è la differenza fra una pagina che sembra rotta e una che
    spiega come si usa.
--}}
<x-layouts.app :meta="$meta">
    <header class="flex flex-col gap-2">
        <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
        <p class="text-sm text-ink-muted">{{ __('account.feed.lead') }}</p>
    </header>

    @if ($occurrences !== null && $occurrences->total() > 0)
        <div class="mt-6" data-results>
            <x-event-grid :occurrences="$occurrences->getCollection()" />
        </div>

        <div class="mt-8" data-pagination>
            <x-pagination :paginator="$occurrences" :summary="true" />
        </div>
    @else
        <section aria-labelledby="avvio" class="mt-8 flex flex-col gap-6">
            <div class="flex flex-col gap-1">
                <h2 id="avvio" class="text-section text-ink">{{ __('account.feed.onboarding_title') }}</h2>
                <p class="text-sm text-ink-muted">{{ __('account.feed.onboarding_lead') }}</p>
            </div>

            @if ($venues !== null && $venues->isNotEmpty())
                <div class="flex flex-col gap-3">
                    <h3 class="text-eyebrow text-ink-subtle uppercase">{{ __('account.feed.onboarding_venues') }}</h3>

                    <ul class="flex flex-col gap-2">
                        @foreach ($venues as $venue)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-card bg-surface px-4 py-3 ring-1 ring-line">
                                <a class="font-semibold text-ink hover:text-brand" href="{{ route('venues.show', $venue) }}">{{ $venue->name }}</a>

                                <x-follow-button
                                    type="venue"
                                    :id="$venue->getKey()"
                                    :following="app(\App\Support\CurrentFollows::class)->has(\App\Enums\FollowableType::Venue, (int) $venue->getKey())"
                                />
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($categories !== null && $categories->isNotEmpty())
                <div class="flex flex-col gap-3">
                    <h3 class="text-eyebrow text-ink-subtle uppercase">{{ __('account.feed.onboarding_categories') }}</h3>

                    <ul class="flex flex-col gap-2">
                        @foreach ($categories as $category)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-card bg-surface px-4 py-3 ring-1 ring-line">
                                <a class="font-semibold text-ink hover:text-brand" href="{{ route('events.category', $category) }}">{{ $category->name }}</a>

                                <x-follow-button
                                    type="category"
                                    :id="$category->getKey()"
                                    :following="app(\App\Support\CurrentFollows::class)->has(\App\Enums\FollowableType::Category, (int) $category->getKey())"
                                />
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="max-w-prose text-xs text-ink-subtle">{{ __('account.follow.hint') }}</p>
        </section>
    @endif
</x-layouts.app>
