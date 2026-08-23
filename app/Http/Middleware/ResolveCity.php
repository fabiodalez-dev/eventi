<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\City;
use App\Support\CurrentCity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fissa la città della richiesta a partire dal segmento `{city}` dell'URL:
 * è ciò che rende possibile `/{city}/eventi` senza renderlo obbligatorio per
 * la città predefinita (§11.1).
 *
 * Le stesse rotte sono registrate due volte, con e senza il prefisso. I
 * controller non hanno alcun parametro `$city` perché il segmento viene
 * **dimenticato** subito dopo essere stato risolto: senza, ogni azione
 * dovrebbe esistere in due versioni.
 *
 * Una città sconosciuta o non ancora accesa (`cities.is_active`) è un 404, non
 * un ripiego silenzioso sulla città predefinita: `/verona/eventi` non deve
 * mostrare gli eventi di Padova.
 */
class ResolveCity
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = $route?->parameter('city');

        if (! is_string($slug)) {
            return $next($request);
        }

        $city = City::query()->active()->where('slug', $slug)->first();

        abort_if($city === null, 404);

        app(CurrentCity::class)->set($city);

        $route->forgetParameter('city');

        return $next($request);
    }
}
