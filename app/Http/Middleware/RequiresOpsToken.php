<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La serratura dell'endpoint di stato (§16: «esposto su endpoint protetto per
 * l'uptime monitor»).
 *
 * Non è il `RequiresSecretToken` del pacchetto, che lascia passare **tutti**
 * quando la chiave non è configurata: un endpoint che si apre da solo quando
 * qualcuno dimentica una riga di `.env` è un endpoint aperto, e questo elenca
 * uno per uno i pezzi del sistema che stanno cedendo. Senza chiave, qui non
 * passa nessuno.
 *
 * La chiave si porta nell'intestazione `X-Secret-Token` — lo stesso nome del
 * pacchetto, così i monitor già configurati continuano a funzionare — oppure
 * nella query string, perché diversi servizi di uptime sanno interrogare solo
 * un indirizzo. Il confronto è a tempo costante: su un segreto, un `===` che
 * si ferma al primo carattere diverso racconta quanti ne aveva indovinati.
 */
final class RequiresOpsToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('health.secret_token');

        if (! is_string($expected) || $expected === '') {
            abort(404);
        }

        $header = $request->header('X-Secret-Token');
        $query = $request->query('token');

        $provided = is_string($header) && $header !== ''
            ? $header
            : (is_string($query) ? $query : '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            abort(404);
        }

        /*
         * Chi arriva fin qui è un programma, non un browser: dichiarare JSON al
         * posto suo fa uscire un corpo leggibile anche quando lo stato è 503,
         * invece della pagina di errore HTML del framework. Un monitor di
         * uptime che registra il corpo della risposta deve trovarci il motivo,
         * non un foglio di stile.
         */
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
