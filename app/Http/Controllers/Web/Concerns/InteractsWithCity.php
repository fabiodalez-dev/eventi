<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Concerns;

use App\Models\City;
use App\Support\CurrentCity;

/**
 * La città della richiesta, o un 404.
 *
 * Il sito pubblico non esiste senza una città accesa: `cities.is_active` nasce
 * falso di proposito (una città si pubblica quando è pronta), e finché nessuna
 * è accesa non c'è alcun catalogo da mostrare. La sola pagina che sopravvive
 * senza città è quella iniziale, che si limita a presentarsi.
 */
trait InteractsWithCity
{
    protected function city(): City
    {
        $city = app(CurrentCity::class)->get();

        abort_if($city === null, 404);

        return $city;
    }
}
