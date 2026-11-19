<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Models\Contracts\FilamentUser;

/**
 * La porta dei tre pannelli (redazione, locali, organizzatori).
 *
 * I pannelli non hanno più una pagina di accesso loro: si entra tutti da
 * `/accedi` (login unico). Chi non è collegato va lì, e la pagina che stava
 * aprendo resta ricordata come ritorno (`redirect()->guest()`, nel gestore
 * delle eccezioni). Chi è collegato ma non può aprire il pannello riceve un
 * 403 che dice a chi è riservato, con la pagina d'errore del sito: «Vai alla
 * tua area» lo porta dove può entrare. Il locale di un altro resta un 404:
 * lo decide `IdentifyTenant`, dopo questo controllo.
 */
final class AuthenticatePanel extends Authenticate
{
    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        $user = $guard->user();
        $panel = Filament::getCurrentOrDefaultPanel();

        if (! $user instanceof FilamentUser || ! $user->canAccessPanel($panel)) {
            abort(403, __('auth.panel.forbidden.'.$panel->getId()));
        }
    }

    protected function redirectTo($request): string
    {
        return route('login');
    }
}
