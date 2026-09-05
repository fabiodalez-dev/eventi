<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `ETag` e `Cache-Control` delle liste pubbliche (§13), con il 304 che ne
 * discende.
 *
 * `stale-while-revalidate` è la ragione per cui questo middleware esiste
 * invece del `cache.headers` di Laravel, che non sa emetterlo: senza, ogni
 * scadenza del minuto costringe qualcuno ad aspettare la risposta nuova.
 *
 * **Una risposta autenticata non è pubblica.** Se il chiamante ha un token,
 * l'occorrenza porta `is_saved` (§15.8) e la risposta parla di lui solo: la
 * direttiva diventa `private`, altrimenti una cache condivisa mostrerebbe i
 * salvataggi di uno a tutti gli altri.
 */
final class CacheJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable() || $response->getStatusCode() !== 200) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false || $content === '') {
            return $response;
        }

        $response->setEtag(md5($content));

        $authenticated = $request->user('sanctum') !== null;

        $response->headers->set('Cache-Control', sprintf(
            '%s, max-age=%d, stale-while-revalidate=%d',
            $authenticated ? 'private' : 'public',
            config()->integer('api.cache.max_age'),
            config()->integer('api.cache.stale_while_revalidate'),
        ));

        if ($authenticated) {
            $response->headers->set('Vary', 'Authorization, X-Installation-ID');
        }

        /*
         * `isNotModified()` svuota il corpo e porta la risposta a 304: è la
         * metà del lavoro per cui l'ETag esiste, e va fatta qui perché è qui
         * che l'ETag è stato appena calcolato.
         */
        $response->isNotModified($request);

        return $response;
    }
}
