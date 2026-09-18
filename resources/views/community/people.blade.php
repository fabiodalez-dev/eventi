<x-layouts.app :meta="$meta">
    @include('community.nav')
    <header class="max-w-3xl">
        <p class="mb-3 font-display text-xs font-extrabold tracking-widest text-brand uppercase">{{ __('community.people_eyebrow') }}</p>
        <h1 class="text-hero">{{ $meta->heading }}</h1>
        <p class="mt-4 max-w-2xl text-base leading-relaxed text-ink-muted">{{ __('community.follow_help') }}</p>
    </header>

    <form method="get" class="mt-8 mb-10 border-y border-line py-6 sm:mt-10" role="search" aria-label="{{ __('community.search') }}">
        <label for="people-q" class="mb-3 block text-sm font-semibold">{{ __('community.search_label') }}</label>
        <div class="flex max-w-3xl flex-col gap-3 sm:flex-row">
            <div class="relative min-w-0 flex-1">
                <svg aria-hidden="true" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
                <input id="people-q" name="q" type="search" maxlength="80" value="{{ request('q') }}" placeholder="{{ __('community.search_hint') }}" class="h-13 w-full border border-line bg-canvas py-3 pr-4 pl-12 text-base placeholder:text-ink-muted">
            </div>
            <x-button type="submit" class="h-13 shrink-0 gap-6 sm:px-6">{{ __('community.search_action') }}<svg aria-hidden="true" class="ml-auto size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12h15m-6-6 6 6-6 6"/></svg></x-button>
        </div>
        <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-3">
            <label class="group inline-flex min-h-11 cursor-pointer items-center gap-3 text-sm">
                <input type="checkbox" name="featured" value="1" class="size-4 shrink-0 accent-[var(--brand)]" @checked(request()->boolean('featured'))>
                <span class="inline-flex items-center gap-2 font-semibold group-hover:text-brand"><svg aria-hidden="true" class="size-4 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m12 3 2.8 5.7 6.3.9-4.6 4.5 1.1 6.3L12 17.4l-5.6 3 1.1-6.3L3 9.6l6.2-.9Z"/></svg>{{ __('community.featured_only') }}</span>
            </label>
            @if(request()->filled('q') || request()->boolean('featured'))
                <a href="{{ route('community.people') }}" class="inline-flex min-h-11 items-center text-sm text-ink-muted underline underline-offset-4 hover:text-ink">{{ __('community.clear_search') }}</a>
            @endif
        </div>
    </form>

    @if($profiles->isNotEmpty())
        <div class="mb-2 flex items-baseline justify-between gap-4"><h2 class="font-display text-lg font-bold">{{ request()->filled('q') ? __('community.search_results') : __('community.people_heading') }}</h2><span class="shrink-0 text-sm text-ink-muted">{{ trans_choice('community.people_count', $profiles->total(), ['count' => $profiles->total()]) }}</span></div>
        <div class="grid gap-x-12 md:grid-cols-2">
            @foreach($profiles as $profile)
                <article class="flex items-start gap-4 border-t border-line py-6 sm:gap-5">
                    <a href="{{ route('community.profile', $profile->handle) }}" tabindex="-1" aria-hidden="true" class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full border border-line bg-surface font-display text-2xl font-bold text-brand">
                        @if($profile->avatarUrl())<img src="{{ $profile->avatarUrl() }}" alt="" width="64" height="64" class="size-full object-cover" loading="lazy">@else{{ mb_strtoupper(mb_substr($profile->display_name, 0, 1)) }}@endif
                    </a>
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('community.profile', $profile->handle) }}" class="font-display text-xl leading-tight font-bold break-words hover:text-brand">{{ $profile->display_name }}</a>
                        <p class="mt-1 text-sm text-ink-muted">{{ '@'.$profile->handle }}@if($profile->city) · {{ $profile->city->name }}@endif</p>
                        <p class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-brand"><svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>{{ __('community.verified') }}</p>
                        @if($profile->bio)<p class="mt-3 line-clamp-3 text-sm leading-relaxed text-ink-muted">{{ $profile->bio }}</p>@endif
                        @if($profile->featured)<p class="mt-3 text-xs font-semibold">{{ __('community.featured') }}</p>@endif
                        <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2">
                            @if(auth()->id() !== $profile->user_id)
                                @auth
                                    @php($following = in_array($profile->user_id, $followingIds, true))
                                    <form method="post" action="{{ route('community.follow', $profile->user_id) }}">@csrf @if($following) @method('DELETE') @endif
                                        <x-button type="submit" :variant="$following ? 'secondary' : 'primary'" :aria-label="__($following ? 'community.unfollow_person' : 'community.follow_person', ['name' => $profile->display_name])">
                                            <span aria-hidden="true">{{ $following ? '✓' : '+' }}</span>{{ __($following ? 'community.following_label' : 'community.follow') }}
                                        </x-button>
                                    </form>
                                @else
                                    <x-button :href="route('login', ['intended' => request()->fullUrl()])"><span aria-hidden="true">+</span>{{ __('community.follow') }}</x-button>
                                @endauth
                            @endif
                            <a href="{{ route('community.profile', $profile->handle) }}" class="inline-flex min-h-11 items-center gap-3 text-sm font-semibold hover:underline">{{ __('community.see_recommendations') }}<span aria-hidden="true">→</span></a>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="flex max-w-xl items-start gap-5 py-3 sm:py-6">
            <span aria-hidden="true" class="flex size-12 shrink-0 items-center justify-center rounded-full bg-surface text-ink-muted"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg></span>
            <div><h2 class="font-display text-xl font-bold">{{ __('community.no_people_title') }}</h2><p class="mt-2 text-sm leading-relaxed text-ink-muted">{{ request()->filled('q') || request()->boolean('featured') ? __('community.no_people_help') : __('community.people_start_help') }}</p></div>
        </div>
    @endif
    <x-pagination :paginator="$profiles" />
</x-layouts.app>
