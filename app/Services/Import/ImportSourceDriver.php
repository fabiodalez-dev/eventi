<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\DTOs\ImportedEventDto;
use App\Exceptions\ImportException;
use App\Models\ImportSource;

/**
 * L'interfaccia comune di §14.2: `fetch()` scarica e interpreta, `map()`
 * normalizza. Le sorgenti future — il Comune, i teatri, i cinema, un portale,
 * un feed RSS, un'API — si aggiungono **senza toccare il core**: bastano una
 * classe che implementi questi due metodi e una riga in `ImportDriverFactory`.
 *
 * La divisione fra i due metodi non è estetica. `fetch()` è l'unico punto che
 * parla con il mondo esterno e l'unico che può fallire in blocco; `map()` non
 * conosce la rete, prende una voce alla volta e non fallisce mai — restituisce
 * `null` per ciò che non sa interpretare, e il chiamante lo conta. È questa
 * separazione che permette all'anteprima di §14.2 di percorrere l'intera
 * catena senza scrivere una riga.
 */
interface ImportSourceDriver
{
    /**
     * Scarica il contenuto della sorgente e lo riduce a un elenco di voci
     * grezze, ciascuna nella forma naturale del formato: un `VEVENT` per
     * l'ICS, un oggetto per il JSON, un `<item>` per l'RSS.
     *
     * @return list<mixed>
     *
     * @throws ImportException quando la sorgente è irraggiungibile o illeggibile
     */
    public function fetch(ImportSource $source): array;

    /**
     * Normalizza una voce grezza. `null` significa «questa voce non è
     * interpretabile»: manca l'identificativo, manca il titolo, manca la data
     * di inizio, o le date che dichiara non stanno in piedi.
     */
    public function map(mixed $raw, ImportSource $source): ?ImportedEventDto;
}
