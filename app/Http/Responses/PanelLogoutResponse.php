<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Illuminate\Http\RedirectResponse;

/**
 * «Esci» dal menu di un pannello: si torna al login unico.
 *
 * Prima si finiva sulla pagina di accesso di quel pannello (per esempio
 * `/gestione/login`), dove la redazione non poteva entrare: chi gestisce un
 * locale e lavora anche in redazione restava chiuso fuori dalla porta
 * sbagliata. `/accedi` vale per tutti, e dopo l'accesso ognuno torna nella
 * propria area.
 */
final class PanelLogoutResponse implements LogoutResponse
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->with('status', __('account.login.logged_out'));
    }
}
