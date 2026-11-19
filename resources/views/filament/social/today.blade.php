<x-filament-widgets::widget>
    @if (($dates = $this->dates())->isNotEmpty())
        <x-filament::section :heading="__('social.today')" icon="heroicon-o-photo">
            <div class="flex flex-col gap-5">
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button wire:click="downloadToday" wire:loading.attr="disabled" icon="heroicon-o-arrow-down-tray">{{ __('social.download_today') }}</x-filament::button>
                    <x-filament::button color="gray" tag="a" :href="\App\Filament\Admin\Pages\Social::getUrl()">{{ __('social.open') }}</x-filament::button>
                    <span wire:loading wire:target="downloadToday" class="text-sm text-gray-600 dark:text-gray-400" role="status">{{ __('social.generating') }}</span>
                </div>
                <ul class="-mx-1 flex snap-x gap-4 overflow-x-auto px-1 pb-2">
                    @foreach ($dates as $date)
                        <li class="w-44 shrink-0 snap-start" wire:key="today-{{ $date->id }}">
                            <a href="{{ \App\Filament\Admin\Pages\Social::getUrl(['event' => $date->event_id]) }}" class="flex flex-col gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                                <img loading="lazy" src="{{ \App\Services\Social\SocialStudio::previewUrl($date) }}" alt="" class="aspect-[4/5] w-44 rounded-lg border border-gray-200 bg-gray-100 object-contain dark:border-white/10 dark:bg-white/5">
                                <span class="[overflow-wrap:anywhere]">{{ $date->event->title }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
