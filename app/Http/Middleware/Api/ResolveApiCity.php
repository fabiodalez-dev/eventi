<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\City;
use App\Support\CurrentCity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La città della richiesta API, scelta dal parametro `city` di §13.2.
 *
 * È lo stesso mestiere del middleware `ResolveCity` del sito, con due
 * differenze: la città arriva dalla query string invece che dal percorso, ed
 * è facoltativa — senza, vale la città predefinita, che è ciò che fa un'app
 * appena installata prima di sapere dove si trova chi la usa.
 *
 * La città finisce in `CurrentCity`, che è già ciò da cui la leggono motore
 * temporale, formattazione delle date e controller: nessun controller
 * dell'API ha quindi un parametro in più.
 */
final class ResolveApiCity
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->query('city');

        if (is_string($slug) && trim($slug) !== '') {
            $city = City::query()->active()->where('slug', trim($slug))->first();

            if (! $city instanceof City) {
                throw new ApiException(ApiErrorCode::CityNotFound);
            }

            app(CurrentCity::class)->set($city);
        }

        return $next($request);
    }
}
