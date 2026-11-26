<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Editorial\EditorialUnlocks;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le azioni pericolose della redazione (decisione 4: unire eventi e tag,
 * eliminare, sospendere, annullare un evento, cambiare i ruoli, modificare
 * una città): 423 `PASSWORD_REQUIRED` se la password dell'account non è
 * stata scritta negli ultimi 15 minuti su questo token. L'app la chiede,
 * chiama `POST editorial/unlock` e ripete la richiesta.
 *
 * Una rotta diventa pericolosa con `->middleware(RequireRecentPassword::class)`
 * nel file del suo pacchetto; `EditorialRoutesTest` controlla che le rotte
 * con i nomi delle azioni pericolose ce l'abbiano. Le azioni pericolose solo
 * con certi dati chiamano `EditorialUnlocks::requirePassword()` dal
 * controller.
 */
final class RequireRecentPassword
{
    public function __construct(private readonly EditorialUnlocks $unlocks) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->unlocks->requirePassword($request);

        return $next($request);
    }
}
