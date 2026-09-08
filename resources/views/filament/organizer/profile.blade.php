<x-filament-panels::page>
    <p>Nome, responsabile e collaboratori sono gestiti dalla redazione. Qui puoi aggiornare la presentazione e i contatti pubblici.</p>
    <form wire:submit="save" class="flex flex-col gap-6">
        {{ $this->form }}
        <x-filament::button type="submit">Salva il profilo</x-filament::button>
    </form>
</x-filament-panels::page>
