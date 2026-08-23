<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Entrare nei panni di un altro utente e uscirne (§9.2, «Utenti — ruoli,
 * impersonate»). Serve a riprodurre quello che una persona vede quando
 * segnala che qualcosa non funziona.
 *
 * Tre precauzioni, tutte necessarie:
 *
 * 1. **Chi può** lo decide `UserPolicy::impersonate()`, che vieta di
 *    impersonare se stessi e chiunque abbia più poteri di chi ci prova.
 * 2. **L'identità originale** resta in sessione sotto una chiave sola, e il
 *    ritorno la verifica: senza, chiunque fosse impersonato potrebbe chiamare
 *    la rotta di uscita e trovarsi amministratore.
 * 3. **La sessione cambia identificatore** a ogni cambio di identità, perché
 *    due utenti diversi non devono mai condividere la stessa sessione.
 *
 * Chi non è autenticato viene mandato all'accesso del pannello di redazione e
 * non a una rotta `login` del sito pubblico: quella nascerà con gli account
 * degli utenti (§15.2), e inventarla qui vorrebbe dire scriverne il nome
 * prima che esista.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(User $user): RedirectResponse
    {
        $impersonator = Auth::user();

        if (! $impersonator instanceof User) {
            return redirect(self::loginUrl());
        }

        abort_unless($impersonator->can('impersonate', $user), 403);

        session()->put(self::SESSION_KEY, $impersonator->getKey());

        // `Auth::login()` rigenera già l'identificatore di sessione
        // (`SessionGuard::updateSession()` chiama `migrate(true)`): due
        // identità diverse non condividono mai la stessa sessione.
        Auth::login($user);

        return redirect('/');
    }

    public function stop(): RedirectResponse
    {
        if (! Auth::check()) {
            return redirect(self::loginUrl());
        }

        $impersonatorId = session()->pull(self::SESSION_KEY);

        abort_if($impersonatorId === null, 403);

        $impersonator = User::query()->find($impersonatorId);

        abort_unless($impersonator instanceof User, 403);

        Auth::login($impersonator);

        return redirect('/admin');
    }

    private static function loginUrl(): string
    {
        return Filament::getPanel('admin')->getLoginUrl() ?? url('/');
    }
}
