<x-filament-panels::page>
    <p style="max-width:75ch">{{ __('cache_settings.intro') }}</p>
    <x-filament::section :heading="__('cache_settings.litespeed_title')">
        <p>{{ __('cache_settings.litespeed_help') }}</p>
        <p class="mt-3">{{ __('cache_settings.redis_extension') }}: {{ __(extension_loaded('redis') ? 'cache_settings.available' : 'cache_settings.unavailable') }}</p>
        <p class="mt-3">{{ __('cache_settings.server') }}: {{ str_contains(strtolower(request()->server('SERVER_SOFTWARE', '')), 'litespeed') ? 'LiteSpeed' : __('cache_settings.other_server') }}</p>
    </x-filament::section>
    <form wire:submit="save" style="display:grid;gap:24px">
        {{ $this->getSchema('form') }}
        <div style="display:flex;flex-wrap:wrap;gap:16px">
            <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('cache_settings.save') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="verify" wire:loading.attr="disabled">{{ __('cache_settings.verify') }}</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
