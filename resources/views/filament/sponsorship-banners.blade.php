<x-filament-panels::page>
    <p>I banner orizzontali mostrano automaticamente foto, titolo, data, locale e prezzo degli eventi sponsorizzati, con la dicitura AD. Le campagne si alternano rispettando priorità, peso e limiti già impostati.</p>
    <p>Gli eventi conclusi non vengono più sponsorizzati dal giorno successivo, nel fuso della città. Le date annullate, rinviate o spostate sono escluse. Restano validi solo gli eventi con almeno una data valida.</p>
    <form wire:submit="save" class="space-y-6">
        {{ $this->getSchema('form') }}
        <x-filament::button type="submit" wire:loading.attr="disabled">Salva impostazioni</x-filament::button>
    </form>
    <p>Lo spegnimento riguarda questi banner aggiuntivi, non le collocazioni originali delle campagne. Si applica subito alle nuove richieste e, entro un minuto, alle pagine e alle app già aperte e connesse.</p>
</x-filament-panels::page>
