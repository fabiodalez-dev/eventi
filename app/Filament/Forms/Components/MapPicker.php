<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

/**
 * Una mappa con un segnaposto trascinabile, al posto di due caselle di numeri.
 *
 * **Il problema che risolve.** Latitudine e longitudine si scrivevano a mano.
 * Nessuno le conosce a memoria: chi compila copia quelle di un'altra scheda o
 * lascia il valore d'ufficio, ed è esattamente quello che è successo — locali
 * accatastati nel punto esatto del centro città, e la ricerca «vicino a me»
 * che rispondeva sul posto sbagliato.
 *
 * Il campo non salva niente di suo: **governa altri due campi**, quelli veri.
 * Restano nel modulo, perché a volte una coordinata la si ha davvero — presa
 * da un catasto, da un GPS — e riscriverla a mano dev'essere possibile. La
 * mappa la segue e loro seguono la mappa.
 *
 * **Perché non un pacchetto.** Ne esistono per Filament, ma tutti portano una
 * dipendenza da Google Maps con la sua chiave e la sua fattura, oppure una
 * versione di Leaflet diversa da quella che il sito usa già — e due Leaflet
 * nello stesso progetto sono due comportamenti da tenere allineati. Qui la
 * libreria è la stessa delle mappe pubbliche.
 */
class MapPicker extends Field
{
    protected string $view = 'filament.forms.components.map-picker';

    protected string $latField = 'lat';

    protected string $lngField = 'lng';

    protected ?string $addressField = 'address';

    protected ?string $municipalityField = 'municipality';

    /** I campi che questa mappa governa. */
    public function coordinateFields(string $lat, string $lng): static
    {
        $this->latField = $lat;
        $this->lngField = $lng;

        return $this;
    }

    /**
     * I campi da cui ricavare l'indirizzo per il pulsante «trova sulla mappa».
     *
     * `null` spegne il pulsante: ha senso dove un indirizzo non c'è, come su
     * un evento in un luogo che non è un locale e di cui si conosce solo il
     * nome.
     */
    public function addressFields(?string $address, ?string $municipality = null): static
    {
        $this->addressField = $address;
        $this->municipalityField = $municipality;

        return $this;
    }

    public function getLatField(): string
    {
        return $this->latField;
    }

    public function getLngField(): string
    {
        return $this->lngField;
    }

    public function getAddressField(): ?string
    {
        return $this->addressField;
    }

    public function getMunicipalityField(): ?string
    {
        return $this->municipalityField;
    }
}
