<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'HTML non deve mai finire in una cache **condivisa**: porta il token CSRF
 * della sessione, e servito a un altro visitatore ogni suo invio sarebbe un
 * 419. È per questo che ogni risposta HTML esce `private`, e che LiteSpeed
 * riceve l'ordine esplicito di non trattenerla.
 *
 * ## Perché `no-cache` e non `no-store`
 *
 * I due si somigliano e dicono cose opposte. `no-cache` significa «conserva
 * pure, ma **chiedi prima di riusare**»; `no-store` significa «**non scrivere
 * nulla, da nessuna parte**». Contro le cache condivise proteggono uguale —
 * quel lavoro lo fa `private` — e senza ETag sull'HTML nessuno dei due
 * permette di riusare una copia senza rifare la richiesta.
 *
 * La differenza sta altrove, e su un calendario di eventi pesa: la
 * **back/forward cache** del browser non è una cache di rete, è il ripristino
 * della pagina viva — posizione di scorrimento, filtri aperti, cuori già
 * dipinti. `no-cache` la consente, `no-store` la vieta. Su questo sito il
 * gesto più frequente è «apro una scheda, torno indietro, ne apro un'altra»:
 * con `no-store` ogni ritorno era una richiesta completa più un nuovo disegno.
 *
 * ## Ciò che chiede `no-store` continua ad averlo
 *
 * Questo middleware sta in **testa** al gruppo `web`, quindi sulla via del
 * ritorno è l'ultimo a scrivere: prima riscriveva l'intestazione di tutti,
 * `TicketingPrivacy` compreso. Adesso un `no-store` dichiarato più a valle
 * viene rispettato, ed è l'unica forma in cui quella richiesta può arrivare
 * fin qui — biglietti, prenotazioni, anteprime e collegamenti al calendario
 * restano fuori da qualunque memoria del browser.
 */
final class PreventSharedResponseCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        /*
         * Chi ha chiesto `no-store` lo ha chiesto per una ragione — dati di
         * una prenotazione, un'anteprima non pubblicata — e non è questo il
         * punto in cui rivedere quella decisione.
         */
        if ($response->headers->hasCacheControlDirective('no-store')) {
            return $response;
        }

        /*
         * E chi si è dichiarato `public` sa di poterlo fare.
         *
         * Oggi è il solo riquadro incorporabile (`/widget/{venue}`), che è
         * fuori da `StartSession` e non ha in pagina né cookie né token: la
         * ragione per cui questo middleware esiste lì non si applica. Chiede
         * `public, max-age=900` perché sta nella pagina di qualcun altro e
         * viene ricaricato da ogni suo visitatore — e fino a qui quella riga
         * veniva riscritta, in silenzio, dal middleware messo a proteggere un
         * token che quella risposta non contiene. Una difesa che continua a
         * girare dove non serve non è gratis: costa la funzione che annulla.
         */
        if ($response->headers->hasCacheControlDirective('public')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }
}
