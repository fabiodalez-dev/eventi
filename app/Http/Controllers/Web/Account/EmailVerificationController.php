<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\SignedEmailVerification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Verifica dell'indirizzo (§15.2): obbligatoria **prima di qualunque invio**.
 *
 * Un account non verificato può salvare, non può ricevere — è
 * `User::canReceiveNotifications()` a dirlo, e questa pagina è il solo modo di
 * cambiare quella risposta.
 *
 * La conferma **non pretende una sessione**: chi apre il messaggio dal
 * telefono può non essere collegato lì, e chiedergli di accedere prima di
 * confermare significherebbe rimandarlo a una pagina di accesso partendo da
 * un collegamento che era già una prova di identità. La prova è la firma.
 */
final class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasVerifiedEmail()) {
            return redirect()->route('account.profile');
        }

        return view('account.verify', [
            'email' => $user?->email,
            'meta' => new PageMeta(
                title: __('account.verify.title'),
                heading: __('account.verify.title'),
                indexable: false,
            ),
        ]);
    }

    public function verify(Request $request, SignedEmailVerification $verification): RedirectResponse
    {
        $user = $verification->verify($request->fullUrl());

        if ($user === null) {
            return redirect()->route('login')->with('status', __('account.verify.failed'));
        }

        return redirect()
            ->route($request->user() === null ? 'login' : 'account.profile')
            ->with('status', __('account.verify.done'));
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', __('account.verify.sent'));
    }
}
