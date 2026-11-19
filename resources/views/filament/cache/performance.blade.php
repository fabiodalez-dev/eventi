<x-filament-panels::page>
    <p class="max-w-prose text-sm text-gray-700 dark:text-gray-300">{{ __('cache_settings.intro') }}</p>
    <x-filament::section :heading="__('cache_settings.litespeed_title')">
        <div class="flex flex-col gap-3 text-sm text-gray-950 dark:text-white">
            <p>{{ __('cache_settings.litespeed_help') }}</p>
            <p>{{ __('cache_settings.redis_extension') }}: {{ __(extension_loaded('redis') ? 'cache_settings.available' : 'cache_settings.unavailable') }}</p>
            <p>{{ __('cache_settings.server') }}: {{ str_contains(strtolower(request()->server('SERVER_SOFTWARE', '')), 'litespeed') ? 'LiteSpeed' : __('cache_settings.other_server') }}</p>
        </div>
    </x-filament::section>
    <form wire:submit="save" class="flex flex-col gap-6">
        {{ $this->getSchema('form') }}
        <div class="flex flex-wrap gap-4">
            <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('cache_settings.save') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="verify" wire:loading.attr="disabled">{{ __('cache_settings.verify') }}</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
