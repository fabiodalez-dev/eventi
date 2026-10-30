<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Account\AuthEntryRequest;
use App\Models\User;
use App\Services\Account\SignedEmailVerification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Verifica dell'indirizzo (§15.2): obbligatoria **prima di qualunque invio**.
 *
 * Un account non verificato deve confermare la mail prima di salvare,
 * seguire persone o chiedere la verifica WhatsApp.
 *
 * La conferma **non pretende una sessione**: chi apre il messaggio dal
 * telefono può non essere collegato lì, e chiedergli di accedere prima di
 * confermare significherebbe rimandarlo a una pagina di accesso partendo da
 * un collegamento che era già una prova di identità. La prova è la firma.
 */
final class EmailVerificationController extends Controller
{
    public function notice(AuthEntryRequest $request): View|RedirectResponse
    {
        $request->rememberDestination();
        $user = $request->user();

        if ($user instanceof User && $user->hasVerifiedEmail()) {
            return redirect()->intended(route('account.profile'));
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

    public function verify(Request $request, SignedEmailVerification $verification): RedirectResponse|Response
    {
        $currentUser = $request->user();
        $user = $verification->verify($request->fullUrl(), $currentUser instanceof User ? $currentUser : null);

        if ($user === null) {
            return redirect()->route('login')->with('status', __('account.verify.failed'));
        }

        if ($currentUser === null) {
            // L'onboarding WhatsApp aspetta il login di questo account, non di chiunque entri dopo.
            $request->session()->put('community_onboarding_user', $user->id);

            if ($user->email_claim_pending_at !== null && str_contains((string) $request->userAgent(), 'Android')) {
                return $this->backToApp($request, $user);
            }

            return redirect()->route('login')->with('status', __('account.verify.done'));
        }
        if ($currentUser->is($user)) {
            $currentUser->refresh();

            return (config('community.enabled') ? redirect()->route('community.whatsapp') : redirect()->intended(route('account.profile')))
                ->with('status', __('account.verify.done'));
        }

        // Collegato con un altro account: la conferma vale, ma niente onboarding né
        // messaggi che parlino a nome dell'account appena confermato.
        return redirect()->route('account.profile')->with('status', __('account.verify.confirmed'));
    }

    /**
     * La conferma aperta dal telefono senza sessione, da chi forse si è
     * iscritto dall'app.
     *
     * Qui la firma verifica l'indirizzo ma non dice chi ha scelto la password
     * (`SignedEmailVerification`), e il primo collegamento di accesso
     * azzererebbe password e token dell'app (`ClaimMailbox`). La pagina offre
     * allora di riportare lo **stesso collegamento firmato** nell'app, che lo
     * rimanda a `POST /v1/auth/verify-email` con il proprio token: se il token
     * è dell'account confermato, il sospeso si chiude come dalla sessione del
     * sito.
     *
     * L'`intent://` nomina il pacchetto: Android lo consegna solo all'app
     * ufficiale, non a un'app qualunque che dichiari lo stesso schema, e senza
     * l'app il browser ripiega sulla pagina di accesso. Chi si è preregistrato
     * con l'indirizzo di un altro non ha il collegamento, che sta nella casella
     * della vittima: la difesa contro l'account creato in anticipo non cambia.
     */
    private function backToApp(Request $request, User $user): Response
    {
        $fallback = route('login');
        $intent = 'intent://auth/verify-email?url='.rawurlencode($request->fullUrl())
            .'#Intent;scheme=incitta;package='.config()->string('account.android_package')
            .';S.browser_fallback_url='.rawurlencode($fallback).';end';

        return response()->view('account.verify-app', [
            'intent' => $intent,
            'fallback' => $fallback,
            'meta' => new PageMeta(
                title: __('account.verify.app_title'),
                heading: __('account.verify.app_title'),
                indexable: false,
            ),
        ])
            /* La pagina contiene il collegamento firmato: non resta in una
               cache e non parte verso altri siti come referrer. */
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
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
