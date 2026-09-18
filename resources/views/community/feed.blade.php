<x-layouts.app :meta="$meta">
    @include('community.nav')
    <header class="max-w-3xl"><h1 class="text-hero">{{ $scope === 'following' ? __('community.feed') : __('community.discover') }}</h1><p class="mt-3 text-ink-muted">{{ __('community.lead') }}</p></header>
    <form class="my-8 flex flex-wrap items-end gap-4" method="get">
        <label class="flex flex-col gap-2 text-sm font-semibold">{{ __('community.nav') }}<select name="scope" class="border-2 border-line bg-canvas p-3"><option value="following" @selected($scope === 'following')>{{ __('community.feed') }}</option><option value="discover" @selected($scope === 'discover')>{{ __('community.discover') }}</option></select></label>
        <label class="flex flex-col gap-2 text-sm font-semibold">{{ __('community.sort') }}<select name="sort" class="border-2 border-line bg-canvas p-3">@foreach(['recent', 'event'] as $sort)<option value="{{ $sort }}" @selected(request('sort', 'recent') === $sort)>{{ __('community.sorts.'.$sort) }}</option>@endforeach</select></label>
        <label class="flex min-h-12 items-center gap-2 text-sm"><input type="checkbox" name="past" value="1" @checked(request()->boolean('past'))>{{ __('community.past') }}</label>
        <x-button type="submit" variant="secondary">{{ __('community.apply') }}</x-button>
    </form>
    <div class="grid items-start gap-x-10 gap-y-6 md:grid-cols-2 xl:grid-cols-3">
        @forelse($posts as $post)@include('community.post-card')@empty<div class="col-span-full border-y border-line py-12"><p class="mb-5 max-w-xl text-ink-muted">{{ __('community.empty') }}</p><x-button :href="route('community.people')">{{ __('community.people') }}</x-button></div>@endforelse
    </div>
    <x-pagination :paginator="$posts" />
</x-layouts.app>
