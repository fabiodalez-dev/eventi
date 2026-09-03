<?php

declare(strict_types=1);

namespace App\Services\Geo;

/**
 * Da un indirizzo a un punto sulla mappa.
 *
 * **Dietro un'interfaccia come `GeoQueryInterface` e `HostResolver`**, e per
 * la stessa ragione: la traduzione di un indirizzo passa da un servizio
 * esterno, e una suite che lo interroga davvero e' lenta, dipende dalla rete e
 * consuma il limite di una richiesta al secondo di un servizio gratuito.
 *
 * Cambiare da Nominatim a un altro fornitore costa una riga nel provider piu'
 * una implementazione.
 */
interface AddressGeocoder
{
    /**
     * @return array{lat: float, lng: float}|null `null` se l'indirizzo non si trova
     */
    public function coordinate(string $indirizzo, ?string $comune = null, ?string $paese = 'Italia'): ?array;
}
