<x-filament-panels::page>
    <p class="text-sm text-gray-700 dark:text-gray-200">{{ __('sponsorships.banners.lead') }}</p>
    <p class="text-sm text-gray-700 dark:text-gray-200">{{ __('sponsorships.banners.rules') }}</p>
    <form wire:submit="save" class="space-y-6">
        {{ $this->getSchema('form') }}
        <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('sponsorships.banners.save') }}</x-filament::button>
    </form>
    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('sponsorships.banners.switch_off') }}</p>
</x-filament-panels::page>
