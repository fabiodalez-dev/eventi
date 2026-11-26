<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
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
 * con i nomi delle azioni pericolose ce l'abbiano.
 *
 * Un'azione che è pericolosa solo in certi casi (rifiutare un ordine di
 * sponsorizzazione già pagato online, che rimborsa su Stripe senza ritorno)
 * chiama `ensure()` dal controller, dopo aver letto il record.
 */
final class RequireRecentPassword
{
    public function __construct(private readonly EditorialUnlocks $unlocks) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->ensure($request);

        return $next($request);
    }

    /**
     * 423 `EDITORIAL_LOCKED` senza sblocco, 423 `PASSWORD_REQUIRED` senza
     * password negli ultimi 15 minuti su questo token.
     *
     * @throws ApiException
     */
    public function ensure(Request $request): void
    {
        $unlock = $this->unlocks->find($request);
        if (! $this->unlocks->unlocked($unlock)) {
            throw new ApiException(ApiErrorCode::EditorialLocked);
        }
        if (! $this->unlocks->passwordConfirmed($unlock)) {
            throw new ApiException(ApiErrorCode::PasswordRequired);
        }
    }
}
