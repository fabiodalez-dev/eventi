{{--
    `/il-mio-feed`: le prossime date di ciò che si segue. In alto la card per
    scegliere locali e categorie (aperta finché non si segue niente), sotto
    l'elenco con le locandine e, per ogni data, il motivo per cui è qui.
    Paginazione esplicita: questo feed non scorre all'infinito.
--}}
@php($saves = app(\App\Support\CurrentSaves::class))
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('decision.feed_rules')" :back="route('account.profile')">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="route('events.index')">{{ __('decision.show_all') }}</x-button>
            @if (config('community.enabled'))
                <x-button variant="secondary" size="sm" :href="route('community.feed')">{{ __('community.feed') }}</x-button>
            @endif
        </x-slot:actions>

        <x-sponsorship-banner :city="$city" />

        <details id="feed-interests" class="svc-card group" @if (! $hasFollows) open @endif>
            <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 sm:px-6 [&::-webkit-details-marker]:hidden">
                <span class="flex min-w-0 flex-col gap-0.5">
                    <span class="font-display text-lg leading-tight font-extrabold text-ink">{{ $hasFollows ? __('account.feed.manage_title') : __('account.feed.onboarding_title') }}</span>
                    <span class="text-sm text-ink-muted">{{ __('account.feed.manage_help') }}</span>
                </span>
                <x-lucide name="chevron-down" class="size-5 shrink-0 text-ink-muted transition group-open:rotate-180" />
            </summary>

            <div class="flex flex-col gap-8 border-t border-line px-5 py-5 sm:px-6">
                <section aria-labelledby="feed-venues" class="flex flex-col gap-3">
                    <h2 id="feed-venues" class="m-0 font-display text-base font-extrabold text-ink">{{ __('account.feed.venues_title') }}</h2>
                    <form method="get" action="{{ route('account.feed') }}#feed-interests" class="flex flex-col gap-2 sm:flex-row">
                        <label for="feed-venue-search" class="sr-only">{{ __('account.feed.venue_search') }}</label>
                        <input id="feed-venue-search" name="venue_q" value="{{ $venueSearch }}" type="search" placeholder="{{ __('account.feed.venue_search') }}" maxlength="120" class="min-h-12 min-w-0 flex-1 border-2 border-line bg-canvas px-3 text-ink">
                        <x-button type="submit" variant="secondary">
                            <x-lucide name="search" class="size-4" />
                            {{ __('account.feed.search') }}
                        </x-button>
                    </form>
                    @if ($venueSearch !== '')
                        <div><x-button variant="ghost" size="sm" :href="route('account.feed', ['venues_page' => 1]).'#feed-interests'">{{ __('account.feed.all_venues') }}</x-button></div>
                    @endif
                    <ul class="m-0 flex list-none flex-col p-0" data-feed-venues>
                        @forelse ($venues as $venue)
                            <li class="flex flex-col gap-1 border-b border-line py-3 last:border-b-0">
                                <div class="flex items-center justify-between gap-3">
                                    <a class="min-w-0 flex-1 font-semibold break-words text-ink no-underline hover:text-brand" href="{{ route('venues.show', $venue) }}">{{ $venue->name }}</a>
                                    <div class="shrink-0"><x-follow-button type="venue" :id="$venue->getKey()" /></div>
                                </div>
                                <x-follow-preferences :venue="$venue" />
                            </li>
                        @empty
                            <li class="py-3 text-sm text-ink-muted">{{ __('account.feed.no_venues') }}</li>
                        @endforelse
                    </ul>
                    <x-pagination :paginator="$venues" :summary="true" :label="__('account.feed.venues_pagination')" />
                </section>

                <section aria-labelledby="feed-categories" class="flex flex-col gap-3">
                    <h2 id="feed-categories" class="m-0 font-display text-base font-extrabold text-ink">{{ __('account.feed.categories_title') }}</h2>
                    <ul class="m-0 flex list-none flex-col p-0" data-feed-categories>
                        @foreach ($categories as $category)
                            <li class="flex items-center justify-between gap-3 border-b border-line py-3 last:border-b-0">
                                <a class="min-w-0 flex-1 font-semibold break-words text-ink no-underline hover:text-brand" href="{{ route('events.category', $category) }}">{{ $category->name }}</a>
                                <div class="shrink-0"><x-follow-button type="category" :id="$category->getKey()" /></div>
                            </li>
                        @endforeach
                    </ul>
                    <x-pagination :paginator="$categories" :summary="true" :label="__('account.feed.categories_pagination')" />
                </section>

                <div>
                    <x-button :href="route('account.feed').'#feed-events'">{{ __('account.feed.refresh') }}</x-button>
                </div>
            </div>
        </details>

        @if ($occurrences !== null && $occurrences->total() > 0)
            <x-card id="feed-events" :title="trans_choice('account.feed.count', $occurrences->total(), ['count' => $occurrences->total()])" padding="none" data-feed-results>
                @foreach ($occurrences as $date)
                    <div class="svc-row px-5 py-4 sm:px-6">
                        <x-service-event-row :occurrence="$date">
                            @if (filled($reasons[$date->id] ?? []))
                                <span class="flex items-center gap-1.5"><x-lucide name="rss" class="size-4 shrink-0" />{{ implode(' '.__('common.separator').' ', $reasons[$date->id]) }}</span>
                            @endif
                            <x-slot:actions>
                                <x-save-heart :occurrence="$date" :saved="$saves->has((int) $date->getKey())" variant="label" />
                            </x-slot:actions>
                        </x-service-event-row>
                    </div>
                @endforeach
            </x-card>
            <div data-feed-pagination>
                <x-pagination :paginator="$occurrences" :summary="true" :label="__('account.feed.pagination')" />
            </div>
        @else
            <div id="feed-events">
                <x-empty-state :title="$hasFollows ? __('account.feed.empty_title') : __('account.feed.onboarding_title')" :description="$hasFollows ? __('account.feed.empty_follows') : __('account.feed.onboarding_lead')">
                    <x-button variant="secondary" :href="route('events.index')">{{ __('decision.show_all') }}</x-button>
                </x-empty-state>
            </div>
        @endif
    </x-service-page>
</x-layouts.app>
