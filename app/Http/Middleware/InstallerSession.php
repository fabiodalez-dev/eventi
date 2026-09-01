<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Durante l'installazione la sessione si scrive su file, con un nome di cookie
 * che non cambia (D42, punto 1).
 *
 * **Il driver.** `SESSION_DRIVER=database` è il default del progetto, ma il
 * wizard esiste proprio perché quel database non c'è ancora: con il driver
 * predefinito la prima pagina dell'installer morirebbe nel tentativo di
 * scrivere la sessione, e il messaggio parlerebbe di una tabella `sessions`
 * invece che di ciò che manca davvero. La cache è già su file (D5), quindi non
 * c'è altro da spostare.
 *
 * **Il nome del cookie.** `config/session.php` lo ricava da `APP_NAME`
 * (`Str::slug(APP_NAME).'-session'`). Il wizard *scrive* `APP_NAME` nel `.env`
 * a metà installazione: dalla richiesta successiva il cookie di sessione
 * cambierebbe nome, il browser continuerebbe a mandare quello vecchio e Laravel
 * aprirebbe una sessione nuova e vuota — cioè token CSRF non valido (419),
 * risposte compilate perse e installazione da rifare dall'inizio. Fissarlo qui
 * lo rende indipendente dal nome che si sta scegliendo. Verificato: senza
 * questa riga, la prima operazione dopo la scrittura del `.env` risponde 419.
 *
 * Sta in testa al gruppo `web` e non fra i middleware delle rotte
 * dell'installer perché `StartSession` appartiene al gruppo: un middleware di
 * rotta girerebbe dopo, cioè quando la sessione è già stata aperta con il
 * driver e il nome sbagliati. Fuori dagli indirizzi dell'installer non fa nulla.
 */
class InstallerSession
{
    /** Il nome del cookie di sessione del wizard, che non dipende da `APP_NAME`. */
    public const COOKIE = 'installazione-session';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('installazione', 'installazione/*')) {
            config([
                'session.driver' => 'file',
                'session.cookie' => self::COOKIE,
            ]);
        }

        return $next($request);
    }
}
