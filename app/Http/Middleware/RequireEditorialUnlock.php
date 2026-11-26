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
 * Su tutte le rotte della redazione tranne `desk`, `options` e `unlock`:
 * 423 `EDITORIAL_LOCKED` se su questo token la password non è stata scritta
 * nelle ultime 12 ore (decisione 3). L'app chiede la password, chiama
 * `POST editorial/unlock` e ripete la richiesta.
 *
 * Va dopo `ResolveEditorialDesk`: chi non è della redazione riceve 404 prima
 * di sapere che la redazione si sblocca.
 */
final class RequireEditorialUnlock
{
    public function __construct(private readonly EditorialUnlocks $unlocks) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->unlocks->unlocked($this->unlocks->find($request))) {
            throw new ApiException(ApiErrorCode::EditorialLocked);
        }

        return $next($request);
    }
}
