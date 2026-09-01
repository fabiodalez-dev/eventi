<x-filament-panels::page>
    <form wire:submit="save" class="flex flex-col gap-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit">
                {{ __('manage.actions.save_venue') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
