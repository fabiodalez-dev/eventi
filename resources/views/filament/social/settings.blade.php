<x-filament-panels::page>
    <p style="max-width:75ch">{{ __('social.setup_help') }}</p>
    <form wire:submit="save" style="display:grid;gap:24px">
        {{ $this->getSchema('form') }}
        <div style="display:flex;flex-wrap:wrap;gap:16px"><x-filament::button type="submit">{{ __('social.save') }}</x-filament::button><x-filament::button color="gray" wire:click="verify" wire:loading.attr="disabled">{{ __('social.verify') }}</x-filament::button></div>
    </form>
</x-filament-panels::page>
