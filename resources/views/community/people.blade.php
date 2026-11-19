{{--
    Le persone da seguire. In cima la ricerca, in una card; sotto i risultati
    in una sola pila di righe: foto o iniziali, nome, nome utente e città, la
    presentazione e il pulsante per seguire. Prima erano due colonne affiancate
    dentro la colonna della pagina.
--}}
@php
    $filtered = request()->filled('q') || request()->boolean('featured');
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$meta->heading"
        :eyebrow="__('community.people_eyebrow')"
        :subtitle="__('community.follow_help')"
        :back="auth()->check() ? route('account.profile') : null"
        :tabs="\App\Services\Community\CommunityNavigation::tabs('people')"
        :tabs-label="__('community.nav')"
    >
        <x-card as="form" method="get" role="search" :aria-label="__('community.search')">
            <div class="flex flex-col gap-1.5">
                <label for="people-q" class="text-sm font-semibold text-ink">{{ __('community.search_label') }}</label>
                <div class="flex min-w-0 flex-col gap-3 sm:flex-row">
                    <div class="relative min-w-0 flex-1">
                        <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-ink-muted"><x-lucide name="search" class="size-5" /></span>
                        <input id="people-q" name="q" type="search" maxlength="80" value="{{ request('q') }}" placeholder="{{ __('community.search_hint') }}" class="min-h-12 w-full border border-line bg-surface py-2.5 pr-3.5 pl-11 text-base text-ink placeholder:text-ink-subtle focus:border-brand focus:outline-none">
                    </div>
                    <x-button type="submit" block>{{ __('community.search_action') }}</x-button>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                <label class="inline-flex min-h-11 cursor-pointer items-center gap-3 text-sm text-ink">
                    <input type="checkbox" name="featured" value="1" class="size-4 shrink-0 accent-[var(--brand)]" @checked(request()->boolean('featured'))>
                    <span class="inline-flex items-center gap-2 font-semibold"><x-lucide name="star" class="size-4 text-brand" />{{ __('community.featured_only') }}</span>
                </label>
                @if ($filtered)
                    <x-button variant="ghost" size="sm" :href="route('community.people')">{{ __('community.clear_search') }}</x-button>
                @endif
            </div>
        </x-card>

        @if ($profiles->isNotEmpty())
            <x-card :title="request()->filled('q') ? __('community.search_results') : __('community.people_heading')" padding="none">
                <x-slot:actions>
                    <span class="text-sm text-ink-muted">{{ trans_choice('community.people_count', $profiles->total(), ['count' => $profiles->total()]) }}</span>
                </x-slot:actions>
                @foreach ($profiles as $person)
                    <article class="svc-row flex min-w-0 items-start gap-4 px-5 py-4 sm:px-6" data-community-person>
                        <a href="{{ route('community.profile', $person->handle) }}" tabindex="-1" aria-hidden="true" class="shrink-0 no-underline">
                            <x-avatar :src="$person->avatarUrl()" :name="$person->display_name" size="lg" />
                        </a>
                        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                            <h3 class="m-0 font-display text-lg leading-tight font-extrabold break-words">
                                <a href="{{ route('community.profile', $person->handle) }}" class="text-ink no-underline hover:text-brand">{{ $person->display_name }}</a>
                            </h3>
                            <p class="m-0 text-sm break-words text-ink-muted">{{ '@'.$person->handle }}@if ($person->city) {{ __('common.separator') }} {{ $person->city->name }}@endif</p>
                            @if ($person->user->isWhatsappVerified() || $person->featured)
                                <p class="m-0 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs font-semibold text-brand">
                                    @if ($person->user->isWhatsappVerified())<span class="inline-flex items-center gap-1"><x-lucide name="check" class="size-4" />{{ __('community.verified') }}</span>@endif
                                    @if ($person->featured)<span class="inline-flex items-center gap-1"><x-lucide name="star" class="size-4" />{{ __('community.featured') }}</span>@endif
                                </p>
                            @endif
                            @if ($person->bio)
                                <p class="m-0 line-clamp-3 text-sm leading-relaxed text-ink-muted">{{ $person->bio }}</p>
                            @endif
                            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                <x-person-follow :user-id="$person->user_id" :following="in_array($person->user_id, $followingIds, true)" :name="$person->display_name" />
                                <x-button variant="ghost" size="sm" :href="route('community.profile', $person->handle)">{{ __('community.see_recommendations') }}<x-lucide name="arrow-right" class="size-4" /></x-button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </x-card>
            <x-pagination :paginator="$profiles" />
        @else
            <x-empty-state :title="__('community.no_people_title')" :description="$filtered ? __('community.no_people_help') : __('community.people_start_help')">
                @if ($filtered)
                    <x-button variant="secondary" :href="route('community.people')">{{ __('community.clear_search') }}</x-button>
                @endif
            </x-empty-state>
        @endif
    </x-service-page>
</x-layouts.app>
