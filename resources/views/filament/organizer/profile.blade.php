<x-filament-panels::page>
    <form wire:submit="save" class="flex flex-col gap-6" data-organizer-profile>
        {{ $this->form }}
        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled">{{ __('panel.organizer_profile.save') }}</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
