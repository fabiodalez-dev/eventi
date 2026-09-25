<x-layouts.app :meta="$meta">
    @include('community.nav')
    <div class="max-w-3xl">
        <h1 class="text-hero">{{ __('community.area.relationships') }}</h1>
        <p class="my-4 text-ink-muted">{{ __('community.relationship_help') }}</p>
        <x-section-tabs :label="__('community.area.relationships')" :items="collect(['following' => 'following_list', 'followers' => 'followers', 'blocks' => 'safety'])->map(fn ($label, $key) => ['href' => route('community.followers', ['tab' => $key]), 'label' => __('community.'.$label), 'active' => $tab === $key])->values()->all()" />
        @if($tab === 'blocks')
            <h2 class="text-section">{{ __('community.blocks') }}</h2>
            <p class="my-4 text-sm text-ink-muted">{{ __('community.block_help') }}</p>
            <ul class="divide-y divide-line">@forelse($blocks as $person)
                <li class="flex min-h-16 items-center justify-between gap-4 py-4"><span>{{ $person['display_name'] }}</span><form data-community-form method="post" action="{{ route('community.unblock', $person['user_id']) }}">@csrf @method('DELETE')<x-button variant="secondary" type="submit">{{ __('community.unblock') }}</x-button></form></li>
            @empty<li class="py-4">{{ __('community.no_blocks') }}</li>@endforelse</ul>
        @else
            <ul class="divide-y divide-line">@forelse($tab === 'following' ? $following : $followers as $person)
                <li class="flex flex-wrap items-center justify-between gap-4 py-4">
                    @if($person['handle'])<a class="inline-flex min-h-12 items-center font-semibold underline" href="{{ route('community.profile', $person['handle']) }}">{{ $person['display_name'] }}</a>@else<span>{{ $person['display_name'] }}</span>@endif
                    @if($tab === 'following')<x-person-follow :user-id="$person['user_id']" :following="true" :name="$person['display_name']" />@endif
                    <details><summary class="min-h-12 cursor-pointer content-center text-sm">{{ __('community.more_actions') }}</summary><p class="my-3 max-w-sm text-sm text-ink-muted">{{ __('community.block_help') }}</p><form data-community-form method="post" action="{{ route('community.block', $person['user_id']) }}">@csrf<x-button variant="secondary" type="submit">{{ __('community.block') }}</x-button></form></details>
                </li>
            @empty<li class="py-5 text-ink-muted">{{ __($tab === 'following' ? 'community.following_empty' : 'community.followers_empty') }} <a class="underline" href="{{ route('community.people') }}">{{ __('community.people') }}</a></li>@endforelse</ul>
            <x-pagination :paginator="$pagination" />
        @endif
    </div>
</x-layouts.app>
