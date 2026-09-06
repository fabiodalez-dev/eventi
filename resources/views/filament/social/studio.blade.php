<x-filament-panels::page>
    <p style="max-width:70ch">{{ __('social.lead') }}</p>
    @if($event !== null)<div><x-filament::button color="gray" wire:click="clearEvent">{{ __('social.clear_event') }}</x-filament::button></div>@endif
    @if($this->socialVenue() === null)
        <div><x-filament::button color="gray" tag="a" :href="\App\Filament\Admin\Pages\SocialSettings::getUrl()">{{ __('social.settings') }}</x-filament::button></div>
    @endif
    <div style="display:flex;flex-wrap:wrap;gap:20px;align-items:end">
        @if($this->socialVenue() === null)
            <label>{{ __('social.city') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="cityId">@foreach(\App\Models\City::orderBy('name')->get() as $city)<option value="{{ $city->id }}">{{ $city->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        @endif
        <label>{{ __('social.date') }}<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="date" /></x-filament::input.wrapper></label>
        <label>{{ __('social.format') }}<x-filament::input.wrapper><x-filament::input.select wire:model="format">@foreach(['portrait','square','story'] as $format)<option value="{{ $format }}">{{ __('social.'.$format) }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
    </div>
    <details><summary style="cursor:pointer;padding:12px 0">{{ __('social.options') }}</summary><div style="display:grid;gap:16px;padding:16px 0">
        <label><input type="checkbox" wire:model="monochrome"> {{ __('social.monochrome') }}</label>
        <label><input type="checkbox" wire:model="showAddress"> {{ __('social.address') }}</label>
        <label><input type="checkbox" wire:model="showPrice"> {{ __('social.price') }}</label>
        <label>{{ __('social.image_fit') }}<x-filament::input.wrapper><x-filament::input.select wire:model="imageFit"><option value="cover">{{ __('social.cover') }}</option><option value="contain">{{ __('social.contain') }}</option></x-filament::input.select></x-filament::input.wrapper></label>
        <label>{{ __('social.short_title') }}<x-filament::input.wrapper><x-filament::input wire:model="graphicTitle" maxlength="300" /></x-filament::input.wrapper></label>
    </div></details>
    @foreach($errors->all() as $error)<p role="alert" style="color:#b42318">{{ $error }}</p>@endforeach
    @php($dates = $this->dates())
    @if($dates->isNotEmpty())
        <div style="display:flex;gap:12px;flex-wrap:wrap"><x-filament::button color="gray" wire:click="selectAll">{{ __('social.select_all') }}</x-filament::button><x-filament::button wire:click="generate" wire:loading.attr="disabled">{{ __('social.generate') }}</x-filament::button><span wire:loading wire:target="generate">{{ __('social.generating') }}</span></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:24px">
        @foreach($dates as $occurrence)
            <label wire:key="date-{{ $occurrence->id }}" style="cursor:pointer;display:block">
                <img src="{{ route('social.preview', $occurrence) }}" alt="{{ __('social.preview') }}: {{ $occurrence->event->title }}" loading="lazy" style="width:100%;aspect-ratio:4/5;object-fit:contain;background:#F5F5F0;border:1px solid #ddd">
                <div style="display:flex;gap:10px;margin-top:10px"><input type="checkbox" wire:model="selected" value="{{ $occurrence->id }}" style="margin-top:4px"><span>{{ $occurrence->event->title }}<br><small>{{ $occurrence->event->venue?->name }} · {{ $occurrence->is_all_day ? __('social.all_day') : $occurrence->starts_at->timezone($this->socialCity()->timezone)->format('H:i') }}</small></span></div>
            </label>
        @endforeach
        </div>
    @else <p>{{ __('social.empty') }}</p> @endif
    @if($batch = $this->batch())
        <section style="border-top:1px solid #999;padding-top:24px;display:grid;gap:20px">
            <h2 style="font-size:24px;font-weight:700">{{ __('social.ready') }}</h2><p>{{ __('social.snapshot') }}</p>
            <div><x-filament::button tag="a" :href="route('social.zip', $batch)">{{ __('social.download') }}</x-filament::button></div>
            <div style="display:flex;overflow-x:auto;gap:16px;padding-bottom:12px">@foreach($batch->items as $i => $item)<a href="{{ \App\Services\Social\SocialStudio::imageUrl($batch, $i) }}" target="_blank" style="flex:0 0 220px"><img src="{{ \App\Services\Social\SocialStudio::imageUrl($batch, $i) }}" alt="{{ $item['title'] }}" style="width:220px"><span>{{ $i+1 }}. {{ $item['title'] }}</span></a>@endforeach</div>
            @if($this->socialVenue() === null)
                @foreach(app(\App\Services\Social\SocialPublisher::class)->captions($batch) as $caption)
                    <div><strong>{{ __('social.caption_preview') }}</strong><p style="white-space:pre-wrap;max-width:70ch">{{ $caption }}</p></div>
                @endforeach
                <div><x-filament::button wire:click="publish" wire:confirm="{{ __('social.confirm') }}" wire:loading.attr="disabled">{{ __('social.publish') }}</x-filament::button></div>
            @else <p style="white-space:pre-wrap">{{ $batch->caption }}</p> @endif
        </section>
    @endif
    @if(($recent = $this->recentBatches())->isNotEmpty())
        <section style="border-top:1px solid #999;padding-top:24px"><h2 style="font-size:22px;font-weight:700">{{ __('social.recent') }}</h2>
        @foreach($recent as $prepared)<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;padding:12px 0;border-bottom:1px solid #ddd"><span>{{ $prepared->date->format('d/m/Y') }} · {{ __('social.'.$prepared->format) }} · {{ count($prepared->items) }} {{ __('social.images') }}</span><x-filament::button size="sm" color="gray" wire:click="openBatch('{{ $prepared->id }}')">{{ __('social.preview') }}</x-filament::button><x-filament::button size="sm" tag="a" :href="route('social.zip', $prepared)">{{ __('social.download') }}</x-filament::button></div>@endforeach
        </section>
    @endif
    @if($this->socialVenue() === null)
        <section wire:poll.15s style="border-top:1px solid #999;padding-top:24px"><h2 style="font-size:22px;font-weight:700">{{ __('social.history') }}</h2>
        @foreach(\App\Models\SocialPublication::latest()->limit(30)->get() as $publication)
            <div style="padding:12px 0;border-bottom:1px solid #ddd">{{ $publication->created_at->timezone($this->socialCity()->timezone)->format('d/m H:i') }} · {{ ucfirst($publication->platform) }} · {{ __('social.part') }} {{ $publication->part+1 }} · <strong>{{ __('social.'.$publication->status->value.'_status') }}</strong>@if($publication->error)<p>{{ $publication->error }}</p>@endif
            @if(in_array($publication->status, [\App\Enums\SocialPublicationStatus::Failed, \App\Enums\SocialPublicationStatus::Uncertain], true))<x-filament::button size="sm" color="gray" wire:click="retryPublication({{ $publication->id }})" wire:confirm="{{ __('social.retry_confirm') }}">{{ __('social.retry') }}</x-filament::button>@endif</div>
        @endforeach
        </section>
    @endif
</x-filament-panels::page>
