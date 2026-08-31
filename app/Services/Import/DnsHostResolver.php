<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * La risoluzione vera, quella del sistema operativo.
 *
 * `gethostbynamel()` legge anche `/etc/hosts` — ed è ciò che serve, perché un
 * nome che punta a `127.0.0.1` attraverso il file degli host è esattamente uno
 * dei modi in cui si prova a far chiamare al server se stesso. Gli indirizzi
 * IPv6 arrivano da `dns_get_record()`, che il primo non conosce.
 *
 * Un nome che non si risolve restituisce un elenco vuoto e non un errore: dire
 * «non esiste» non è compito di chi risolve, e la richiesta fallirà da sé.
 */
final class DnsHostResolver implements HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $addresses = [];

        $ipv4 = @gethostbynamel($host);

        // gethostbynamel restituisce una lista di indirizzi o false: quando e
        // un array, i suoi elementi sono gia stringhe.
        if (is_array($ipv4)) {
            foreach ($ipv4 as $address) {
                $addresses[] = $address;
            }
        }

        $records = @dns_get_record($host, DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ipv6'] ?? null;

                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
