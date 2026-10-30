<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Web\ImpersonationController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Durante l'impersonificazione si guarda, non si agisce.
 *
 * L'impersonificazione serve all'assistenza: vedere quello che vede una
 * persona quando segnala un problema. Non deve diventare il modo di scrivere
 * un commento a suo nome, cancellarle l'account, spedire i suoi biglietti o
 * darle un consenso: nel registro resterebbe solo l'inizio
 * dell'impersonificazione, e il gesto risulterebbe suo.
 *
 * È la stessa riga di `CommunityPrivacy`, senza le condizioni proprie della
 * community. Con il parametro `all` blocca anche le letture: serve agli atti
 * personali che passano da un `GET`, come lo scarico dei propri dati.
 */
final class BlockWhileImpersonating
{
    public function handle(Request $request, Closure $next, string $scope = 'writes'): Response
    {
        abort_if(($scope === 'all' || ! $request->isMethodSafe())
            && $request->hasSession() && $request->session()->has(ImpersonationController::SESSION_KEY), 403, __('carpool.errors.impersonation'));

        return $next($request);
    }
}
