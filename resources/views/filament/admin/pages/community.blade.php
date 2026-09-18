<x-filament-panels::page>
    <div class="flex flex-wrap gap-4">
        <label>{{ __('community.nav') }}<select wire:model.live="section" class="rounded-lg border border-gray-300 bg-white p-3 text-gray-950"><option value="profiles">{{ __('community.people') }}</option><option value="posts">{{ __('community.feed') }}</option><option value="comments">{{ __('community.comments') }}</option><option value="restrictions">{{ __('community.restrictions') }}</option></select></label>
        <label>{{ __('community.search') }}<input type="search" wire:model.live.debounce.400ms="search" maxlength="80" class="rounded-lg border border-gray-300 bg-white p-3 text-gray-950"></label>
    </div>
    <div class="divide-y divide-gray-200 dark:divide-gray-700">
    @foreach($items as $item)
        <article class="space-y-3 py-5" wire:key="{{ $section }}-{{ $item->id }}">
            @if($section === 'restrictions')
                <p>{{ $item->name }} · {{ __('community.restricted_occurrence', ['id' => $item->occurrence_id]) }}</p>
                <x-filament::button color="gray" wire:confirm="{{ __('community.restore_restriction_confirm') }}" wire:click="restoreRestriction({{ $item->id }})">{{ __('community.restore') }}</x-filament::button>
            @elseif($item instanceof \App\Models\CommunityProfile)
                <p class="font-bold">{{ $item->display_name }} · {{ '@'.$item->handle }}</p><p>{{ $item->bio }}</p><p>{{ __('community.profile_visibility.'.$item->visibility->value) }}</p>
                <div class="flex flex-wrap gap-4"><x-filament::button color="gray" wire:click="moderate('profile', {{ $item->id }}, {{ $item->featured ? 'false' : 'true' }})">{{ __($item->featured ? 'community.unfeature' : 'community.feature') }}</x-filament::button>
                <x-filament::button color="warning" wire:click="moderate('user', {{ $item->user_id }}, {{ $item->user->community_suspended_at ? 'true' : 'false' }})">{{ __($item->user->community_suspended_at ? 'community.unsuspend' : 'community.suspend') }}</x-filament::button></div>
            @else
                <p class="font-bold">{{ $item->user->communityProfile?->display_name ?? __('community.member') }} · #{{ $item->id }}</p><p class="whitespace-pre-line">{{ $item->body }}</p>
                @if($item instanceof \App\Models\CommunityPost)<p>{{ $item->occurrence->event->title }}</p>@endif
                <x-filament::button color="gray" wire:click="moderate('{{ $section === 'posts' ? 'post' : 'comment' }}', {{ $item->id }}, {{ $item->status->value === 'published' ? 'false' : 'true' }})">{{ __($item->status->value === 'published' ? 'community.hide' : 'community.restore') }}</x-filament::button>
            @endif
        </article>
    @endforeach
    </div>
    {{ $items->links() }}
</x-filament-panels::page>
