<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Filament\Http\Middleware\AuthenticateSession;

/**
 * La sessione dei pannelli, con l'uscita forzata verso il login unico.
 *
 * Quando la password cambia altrove la sessione aperta nel pannello cade
 * (`AuthenticateSession` confronta l'impronta della password): prima cadeva
 * sulla pagina di accesso del pannello, che non esiste più. Ora si torna a
 * `/accedi`, da cui entra chiunque.
 */
final class AuthenticatePanelSession extends AuthenticateSession
{
    protected function redirectTo($request): string
    {
        return route('login');
    }
}
