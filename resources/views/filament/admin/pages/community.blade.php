@php
    use App\Models\CommunityPost;
    use App\Models\CommunityProfile;
    use Illuminate\Support\Carbon;

    $sections = collect(\App\Filament\Admin\Pages\Community::SECTIONS);
@endphp
<x-filament-panels::page>
    {{-- Che cosa si modera: schede, non una tendina. La ricerca subito sotto,
         con un'etichetta che dice dove cerca. --}}
    <div class="flex min-w-0 flex-col gap-4" data-community-moderation>
        <x-filament::tabs :label="__('admin.community_page.sections_label')" class="max-w-full flex-wrap">
            @foreach ($sections as $tab)
                <x-filament::tabs.item :active="$section === $tab" wire:click="$set('section', '{{ $tab }}')">
                    {{ __('admin.community_page.sections.'.$tab) }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <label class="flex flex-col gap-2">
            <span class="text-sm font-medium text-gray-950 dark:text-white">{{ __('admin.community_page.search.'.($section === 'restrictions' || $section === 'profiles' ? 'name' : 'text')) }}</span>
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.400ms="search" maxlength="80" />
            </x-filament::input.wrapper>
        </label>
    </div>

    @if ($items->isEmpty())
        <x-filament::empty-state icon="heroicon-o-inbox" :heading="__('admin.community_page.empty.'.$section)" :description="$search !== '' ? __('admin.community_page.empty_search') : null" />
    @else
        <x-filament::section>
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($items as $item)
                    <li class="flex min-w-0 flex-col gap-3 py-4 first:pt-0 last:pb-0" wire:key="{{ $section }}-{{ $item->id }}">
                        @if ($section === 'restrictions')
                            <div class="flex min-w-0 flex-col gap-1">
                                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item->name }}</p>
                                <p class="text-sm text-gray-700 [overflow-wrap:anywhere] dark:text-gray-300">{{ __('community.restricted_occurrence', ['title' => $item->event_title, 'date' => Carbon::parse($item->starts_at, 'UTC')->timezone($item->timezone)->format('d/m/Y H:i')]) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('community.restricted_occurrence_id', ['id' => $item->occurrence_id]) }}</p>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                {{ ($this->restoreRestrictionAction)(['id' => $item->id]) }}
                            </div>
                        @elseif ($item instanceof CommunityProfile)
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item->display_name }}</p>
                                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ '@'.$item->handle }}</span>
                                    @if ($item->featured)
                                        <x-filament::badge color="success" icon="heroicon-o-star">{{ __('community.feature') }}</x-filament::badge>
                                    @endif
                                    @if ($item->user?->community_suspended_at)
                                        <x-filament::badge color="warning">{{ __('admin.community_page.suspended') }}</x-filament::badge>
                                    @endif
                                </div>
                                @if (filled($item->bio))
                                    <p class="whitespace-pre-line text-sm text-gray-700 [overflow-wrap:anywhere] dark:text-gray-300">{{ $item->bio }}</p>
                                @endif
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('community.profile_visibility.'.$item->visibility->value) }}</p>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                {{ ($this->featureProfileAction)(['id' => $item->id, 'enabled' => ! $item->featured]) }}
                                {{ ($this->suspendUserAction)(['id' => $item->user_id, 'enabled' => (bool) $item->user?->community_suspended_at]) }}
                            </div>
                        @else
                            @php $published = $item->status->value === 'published'; @endphp
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item->user->communityProfile?->display_name ?? __('community.member') }}</p>
                                    <span class="text-sm text-gray-500 dark:text-gray-400">#{{ $item->id }}</span>
                                    @unless ($published)
                                        <x-filament::badge color="danger">{{ __('admin.community_page.hidden') }}</x-filament::badge>
                                    @endunless
                                </div>
                                <p class="whitespace-pre-line text-sm text-gray-700 [overflow-wrap:anywhere] dark:text-gray-300">{{ $item->body }}</p>
                                @if ($item instanceof CommunityPost)
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $item->occurrence->event->title }}</p>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-3">
                                {{ ($this->hideContentAction)(['kind' => $section === 'posts' ? 'post' : 'comment', 'id' => $item->id, 'enabled' => ! $published]) }}
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>

        <x-filament::pagination :paginator="$items" />
    @endif

</x-filament-panels::page>
