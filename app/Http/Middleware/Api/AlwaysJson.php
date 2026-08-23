<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'API risponde JSON anche a chi non lo ha chiesto.
 *
 * Non è una comodità: senza l'intestazione `Accept`, Laravel considera la
 * richiesta "da browser" e il middleware di autenticazione rimanda alla rotta
 * `login` — che qui non esiste, perché il sito pubblico non ha ancora una
 * pagina di accesso. Il risultato sarebbe un **500 «Route [login] not
 * defined»** al posto del 401 che §13.6 prescrive.
 *
 * Dichiararlo qui, e non chiederlo al client, significa che una chiamata con
 * `curl` senza intestazioni si comporta esattamente come quella dell'app.
 */
final class AlwaysJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
