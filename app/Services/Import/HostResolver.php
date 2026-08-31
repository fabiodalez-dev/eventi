<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * La risoluzione di un nome in indirizzi, isolata dietro un'interfaccia per
 * una ragione sola: `ImportUrlGuard` deve poterla verificare senza dipendere
 * dal DNS della macchina che esegue i test.
 *
 * Restituisce **tutti** gli indirizzi conosciuti per il nome, non il primo:
 * un nome che risponde con un indirizzo pubblico e uno interno va rifiutato,
 * e guardarne uno solo lascerebbe passare proprio il caso da fermare.
 */
interface HostResolver
{
    /**
     * @return list<string> indirizzi IP, in forma testuale; vuoto se il nome
     *                      non si risolve
     */
    public function resolve(string $host): array;
}
