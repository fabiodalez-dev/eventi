<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\City;
use App\Support\Redirect\RegistroRedirect;

/**
 * La rinomina di una città è l'unico caso che vale una riga con il jolly.
 *
 * Il prefisso `/{city}` sta davanti a **tutte** le rotte del sito pubblico
 * (§11.1): una riga per indirizzo vorrebbe dire quante sono le pagine della
 * città, cioè un elenco che nasce già incompleto. `/vecchia/*` →
 * `/nuova/{wildcard}` le copre tutte, comprese quelle che non esistono ancora.
 */
final class CityObserver
{
    public function updated(City $city): void
    {
        if (! $city->wasChanged('slug')) {
            return;
        }

        $vecchio = $city->getOriginal('slug');

        if (! is_string($vecchio) || $vecchio === '') {
            return;
        }

        app(RegistroRedirect::class)->registraCitta($vecchio, $city->slug);
    }
}
