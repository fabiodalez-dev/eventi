<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * Gli stessi filtri della lista, con i limiti della mappa (§13.3).
 *
 * Il tetto è più alto perché il carico è un altro: un marcatore sono sette
 * campi, una card ne sono trenta con la locandina. `sort` resta accettato ma
 * non ha effetto sui punti — la mappa li disegna tutti insieme — ed è la
 * ragione per cui l'endpoint non lo dichiara.
 */
final class MapQueryRequest extends EventQueryRequest
{
    protected function defaultLimit(): int
    {
        return config()->integer('api.limits.map_default');
    }

    protected function maxLimit(): int
    {
        return config()->integer('api.limits.map_max');
    }
}
