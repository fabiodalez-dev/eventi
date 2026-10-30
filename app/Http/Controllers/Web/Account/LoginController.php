<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Actions\Account\RequestRegistration;
use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Account\AuthEntryRequest;
use App\Http\Requests\Web\Account\LoginRequest;
use App\Models\User;
use App\Support\AccountLoginThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Accesso con password (§15.2).
 *
 * Il magic link resta la via raccomandata e sta accanto, non al posto: chi una
 * password l'ha scelta deve poterla usare.
 */
final class LoginController extends Controller
{
    public function create(AuthEntryRequest $request): View
    {
        $request->rememberDestination(freshEntry: true);

        return view('account.login', [
            'meta' => new PageMeta(
                title: __('account.login.title'),
                heading: __('account.login.title'),
                description: __('account.login.lead'),
                indexable: false,
            ),
        ]);
    }

    public function store(LoginRequest $request, RequestRegistration $registration): RedirectResponse
    {
        $credentials = [
            'email' => (string) $request->validated('email'),
            'password' => (string) $request->validated('password'),
        ];

        /* Il tetto per account si conta prima della verifica e si restituisce
           a password giusta (`AccountLoginThrottle`). */
        $tetto = AccountLoginThrottle::chiave('sito', $credentials['email']);
        AccountLoginThrottle::prenotaOppure429($tetto);

        /*
         * La password giusta non basta finché la casella non è dimostrata da
         * chi l'ha scelta (`email_claim_pending_at`, D64): può averla scelta
         * chiunque abbia registrato quell'indirizzo, e con la registrazione
         * che non dice più se un indirizzo è libero quella password non deve
         * diventare la porta di un account preregistrato. Il controllo
         * gira solo dopo la verifica della password, quindi lo scopre solo
         * chi la conosce.
         */
        $sospeso = null;
        $entrato = Auth::attemptWhen($credentials, static function (mixed $utente) use (&$sospeso): bool {
            $sospeso = $utente instanceof User && $utente->email_claim_pending_at !== null ? $utente : null;

            return $sospeso === null;
        }, $request->boolean('remember'));

        if ($sospeso instanceof User) {
            AccountLoginThrottle::restituisci($tetto);
            /* Riparte il messaggio della registrazione, legato a questo
               browser: aperto qui conferma ed entra, e la password resta. */
            $registration->sendFromWeb($sospeso, true, $request->session(), $request->boolean('remember'));

            throw ValidationException::withMessages(['email' => __('account.login.claim_pending')]);
        }

        if (! $entrato) {
            /*
             * Un solo messaggio per «email inesistente» e «password
             * sbagliata»: due messaggi diversi direbbero a chiunque quali
             * indirizzi sono registrati.
             */
            throw ValidationException::withMessages(['email' => __('account.login.failed')]);
        }

        AccountLoginThrottle::restituisci($tetto);
        $request->session()->regenerate();

        $user = $request->user();
        $user?->forceFill(['last_active_at' => CarbonImmutable::now()])->save();

        if ($request->session()->get('community_onboarding_user') === $user?->id && config('community.enabled')) {
            $request->session()->forget('community_onboarding_user');

            return redirect()->route('community.whatsapp');
        }

        return redirect()
            ->intended(route('account.feed'))
            ->with('status', __('account.login.done'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', __('account.login.logged_out'));
    }
}
