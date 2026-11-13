<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Web\ImpersonationController;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un'impersonificazione vale finché chi la fa può ancora farla.
 *
 * `ImpersonationController::start()` controlla i poteri una volta sola, e
 * dopo la sessione è quella della persona impersonata: senza questo
 * controllo, un amministratore a cui si tolgono i ruoli o l'account mentre
 * impersona un collega resterebbe dentro con i poteri del collega finché la
 * sessione non scade, cioè per sempre se continua a usarla.
 *
 * A ogni richiesta con il marcatore si ricarica chi impersona (un account
 * cancellato non si trova più) e si richiede che possa ancora impersonare
 * l'utente corrente. Se non può, la sessione si chiude: niente ritorno a un
 * account che non ha più diritto a niente, e niente sessione rimasta a nome
 * dell'impersonato.
 *
 * Sta nel gruppo `web` (sito e aggiornamenti Livewire) e nei middleware dei
 * tre pannelli, che non usano quel gruppo.
 */
final class RevalidateImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        $impersonatorId = $request->hasSession() ? $request->session()->get(ImpersonationController::SESSION_KEY) : null;
        if ($impersonatorId === null) {
            return $next($request);
        }

        // Senza un utente di sessione sul guard `web` non c'è un'identità presa
        // in prestito da ricontrollare; il marcatore resta e i blocchi delle
        // scritture (`BlockWhileImpersonating`) continuano a vederlo.
        $target = Auth::guard('web')->user();
        if (! $target instanceof User) {
            return $next($request);
        }

        $impersonator = User::query()->find($impersonatorId);

        if (! $impersonator instanceof User || ! $impersonator->can('impersonate', $target)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, __('carpool.errors.impersonation'));
        }

        return $next($request);
    }
}
