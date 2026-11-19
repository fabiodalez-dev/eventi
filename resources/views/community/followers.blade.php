{{--
    Seguiti e follower: chi seguo, chi mi segue, chi ho bloccato. Tre elenchi
    della stessa pagina, scelti con la scelta segmentata; ogni persona è una
    riga con la foto o le iniziali. Bloccare sta dentro «Altre azioni»: è un
    gesto raro e pesante, non va accanto a «Segui già».
--}}
@php
    $people = $tab === 'following' ? $following : $followers;
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="__('community.area.relationships')"
        :subtitle="__('community.relationship_help')"
        :back="route('account.profile')"
        :tabs="\App\Services\Community\CommunityNavigation::tabs('people')"
        :tabs-label="__('community.nav')"
    >
        <x-segmented :label="__('community.area.relationships')" :items="collect(['following' => 'following_list', 'followers' => 'followers', 'blocks' => 'blocked_tab'])
            ->map(fn ($label, $key) => ['href' => route('community.followers', ['tab' => $key]), 'label' => __('community.'.$label), 'active' => $tab === $key])->values()->all()" />

        @if ($tab === 'blocks')
            <x-card :title="__('community.blocks')" :description="__('community.block_help')" padding="none">
                @forelse ($blocks as $person)
                    <x-card.row :title="$person['display_name']">
                        <x-slot:leading><x-avatar :name="$person['display_name']" /></x-slot:leading>
                        <x-slot:trailing>
                            <form data-community-form method="post" action="{{ route('community.unblock', $person['user_id']) }}">
                                @csrf @method('DELETE')
                                <x-button variant="secondary" size="sm" type="submit">{{ __('community.unblock') }}</x-button>
                            </form>
                        </x-slot:trailing>
                    </x-card.row>
                @empty
                    <p class="m-0 px-5 py-6 text-sm text-ink-muted sm:px-6">{{ __('community.no_blocks') }}</p>
                @endforelse
            </x-card>
        @elseif ($people === [])
            <x-empty-state :title="__($tab === 'following' ? 'community.following_empty_title' : 'community.followers_empty_title')" :description="__($tab === 'following' ? 'community.following_empty_help' : 'community.followers_empty_help')">
                <x-button :href="route('community.people')">{{ __('community.people') }}</x-button>
            </x-empty-state>
        @else
            <x-card :title="__($tab === 'following' ? 'community.following_list' : 'community.followers')" padding="none">
                @foreach ($people as $person)
                    <div class="svc-row flex min-w-0 flex-col gap-3 px-5 py-4 sm:px-6" data-community-person>
                        <div class="flex min-w-0 flex-wrap items-center justify-between gap-3">
                            {{-- Foto e nome sono un solo bersaglio: due collegamenti
                                 attaccati che portano allo stesso posto si leggono
                                 come due voci. --}}
                            @if ($person['handle'])
                                <a class="flex min-h-12 min-w-0 items-center gap-3 font-semibold text-ink no-underline hover:text-brand" href="{{ route('community.profile', $person['handle']) }}">
                                    <x-avatar :src="$person['avatar_url'] ?? null" :name="$person['display_name']" />
                                    <span class="min-w-0 break-words">{{ $person['display_name'] }}</span>
                                </a>
                            @else
                                <span class="flex min-h-12 min-w-0 items-center gap-3 font-semibold text-ink">
                                    <x-avatar :src="$person['avatar_url'] ?? null" :name="$person['display_name']" />
                                    <span class="min-w-0 break-words">{{ $person['display_name'] }}</span>
                                </span>
                            @endif
                            @if ($tab === 'following')
                                <x-person-follow :user-id="$person['user_id']" :following="true" :name="$person['display_name']" />
                            @endif
                        </div>
                        <details class="group">
                            <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 text-sm font-semibold text-ink-muted hover:text-ink [&::-webkit-details-marker]:hidden">
                                {{ __('community.more_actions') }}<x-lucide name="chevron-down" class="size-4 transition group-open:rotate-180" />
                            </summary>
                            <div class="mt-2 flex flex-col gap-3">
                                <p class="m-0 text-sm text-ink-muted">{{ __('community.block_help') }}</p>
                                <form data-community-form method="post" action="{{ route('community.block', $person['user_id']) }}">
                                    @csrf
                                    <x-button variant="danger" size="sm" type="submit">{{ __('community.block') }}</x-button>
                                </form>
                            </div>
                        </details>
                    </div>
                @endforeach
            </x-card>
            <x-pagination :paginator="$pagination" />
        @endif
    </x-service-page>
</x-layouts.app>
