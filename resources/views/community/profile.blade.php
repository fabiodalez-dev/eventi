<x-layouts.app :meta="$meta">
    @include('community.nav')
    <header class="flex flex-col gap-5 border-b-2 border-line pb-8 sm:flex-row sm:items-center">
        @if($summary['avatar_url'])<img src="{{ $summary['avatar_url'] }}" alt="" width="112" height="112" class="size-28 rounded-full object-cover">@endif
        <div class="min-w-0 flex-1"><p class="mb-2 text-xs font-semibold text-brand">{{ __('community.verified') }}</p><h1 class="text-hero break-words">{{ $profile->display_name }}</h1>
            <p class="mt-3 text-sm text-ink-muted">{{ '@'.$profile->handle }} @if($profile->city) · {{ $profile->city->name }} @endif</p>
            <p class="mt-3 text-sm">{{ __('community.followers_count', ['count' => $summary['followers_count']]) }} · {{ __('community.following_count', ['count' => $summary['following_count']]) }}</p>
            @if($profile->bio)<p class="mt-4 max-w-2xl whitespace-pre-line break-words leading-relaxed">{{ $profile->bio }}</p>@endif
        </div>
        @auth
            @if($summary['is_own'])<x-button :href="route('community.settings')" variant="secondary">{{ __('community.settings') }}</x-button>
            @else<form method="post" action="{{ route('community.follow', $profile->user_id) }}">@csrf @if($summary['is_following']) @method('DELETE') @endif<x-button type="submit">{{ __($summary['is_following'] ? 'community.unfollow' : 'community.follow') }}</x-button></form>@endif
        @else<x-button :href="route('login')">{{ __('community.follow') }}</x-button>@endauth
    </header>
    @if($venues->isNotEmpty())<section class="my-8"><h2 class="text-section">{{ __('community.venues') }}</h2><ul class="mt-3 flex flex-wrap gap-3">@foreach($venues as $venue)<li><a class="inline-flex min-h-11 items-center border border-line px-4 hover:underline" href="{{ route('venues.show', $venue) }}">{{ $venue->name }}</a></li>@endforeach</ul></section>@endif
    <div class="my-8 flex flex-wrap gap-5 text-sm font-semibold"><a class="underline" href="{{ route('community.profile', $profile->handle) }}">{{ __('community.upcoming') }}</a><a class="underline" href="{{ route('community.profile', ['handle' => $profile->handle, 'past' => 1]) }}">{{ __('community.archive') }}</a></div>
    <div class="grid items-start gap-8 md:grid-cols-2 xl:grid-cols-3">@forelse($posts as $post)@include('community.post-card')@empty<p class="py-8 text-ink-muted">{{ __('community.empty') }}</p>@endforelse</div>
    <x-pagination :paginator="$posts" />
    @if(auth()->check() && !$summary['is_own'])
        @include('community.report', ['subject' => $profile])
        <details class="mt-8 border-t border-line pt-4"><summary class="cursor-pointer text-sm text-ink-muted">{{ __('community.block') }}</summary><p class="my-4 text-sm">{{ __('community.block_help') }}</p><form method="post" action="{{ route('community.block', $profile->user_id) }}">@csrf<x-button type="submit" variant="secondary">{{ __('community.block') }}</x-button></form></details>
    @endif
</x-layouts.app>
