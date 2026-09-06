<x-filament-widgets::widget>
    @if(($dates = $this->dates())->isNotEmpty())
    <x-filament::section :heading="__('social.today')">
        <div style="display:grid;gap:20px">
            <div style="display:flex;flex-wrap:wrap;gap:12px"><x-filament::button wire:click="downloadToday" wire:loading.attr="disabled">{{ __('social.download_today') }}</x-filament::button><x-filament::button color="gray" tag="a" :href="\App\Filament\Admin\Pages\Social::getUrl()">{{ __('social.open') }}</x-filament::button><span wire:loading wire:target="downloadToday">{{ __('social.generating') }}</span></div>
            <div style="display:flex;overflow-x:auto;gap:20px;padding-bottom:12px">@foreach($dates as $date)<a style="flex:0 0 180px" href="{{ \App\Filament\Admin\Pages\Social::getUrl(['event' => $date->event_id]) }}"><img loading="lazy" src="{{ \App\Services\Social\SocialStudio::previewUrl($date) }}" alt="{{ $date->event->title }}" style="width:180px;aspect-ratio:4/5;object-fit:contain"><p style="padding-top:8px">{{ $date->event->title }}</p></a>@endforeach</div>
        </div>
    </x-filament::section>
    @endif
</x-filament-widgets::widget>
