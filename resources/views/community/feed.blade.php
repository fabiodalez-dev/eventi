{{--
    La bacheca: i consigli delle persone che seguo, oppure quelli da scoprire.
    Una sola pila di consigli, ognuno con l'evento e la sua locandina; sopra,
    la scelta fra le due bacheche e l'ordine.
--}}
@php
    $query = fn (array $change) => route('community.feed', array_filter([...request()->only(['sort', 'past']), ...$change], fn ($value) => $value !== null && $value !== ''));
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$scope === 'following' ? __('community.feed') : __('community.discover')"
        :subtitle="__('community.lead')"
        :back="route('account.profile')"
        :tabs="\App\Services\Community\CommunityNavigation::tabs('feed')"
        :tabs-label="__('community.nav')"
    >
        <x-segmented :label="__('community.feed_scope')" :items="[
            ['label' => __('community.scopes.following'), 'href' => $query(['scope' => null]), 'active' => $scope === 'following'],
            ['label' => __('community.scopes.discover'), 'href' => $query(['scope' => 'discover']), 'active' => $scope === 'discover'],
        ]" />

        <x-card as="form" method="get" padding="sm" tone="muted" :aria-label="__('community.sort')">
            @if ($scope !== 'following')<input type="hidden" name="scope" value="{{ $scope }}">@endif
            <div class="flex min-w-0 flex-wrap items-end gap-3">
                <label class="flex min-w-0 flex-1 basis-48 flex-col gap-1.5 text-sm font-semibold text-ink">
                    {{ __('community.sort') }}
                    <select name="sort" class="min-h-12 w-full border border-line bg-surface px-3.5 py-2.5 text-base font-normal text-ink focus:border-brand focus:outline-none">
                        @foreach (['recent', 'event'] as $sort)
                            <option value="{{ $sort }}" @selected(request('sort', 'recent') === $sort)>{{ __('community.sorts.'.$sort) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex min-h-12 cursor-pointer items-center gap-3 text-sm text-ink">
                    <input type="checkbox" name="past" value="1" class="size-4 accent-[var(--brand)]" @checked(request()->boolean('past'))>
                    {{ __('community.past') }}
                </label>
                <x-button type="submit" variant="secondary">{{ __('community.apply') }}</x-button>
            </div>
        </x-card>

        @forelse ($posts as $post)
            @include('community.post-card')
        @empty
            <x-empty-state :title="__('community.empty_title')" :description="__('community.empty')">
                <x-button :href="route('community.people')">{{ __('community.people') }}</x-button>
            </x-empty-state>
        @endforelse

        <x-pagination :paginator="$posts" />
    </x-service-page>
</x-layouts.app>
