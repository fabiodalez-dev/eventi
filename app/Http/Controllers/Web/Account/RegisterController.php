<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Actions\Account\RequestRegistration;
use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Account\AuthEntryRequest;
use App\Http\Requests\Web\Account\RegisterRequest;
use App\Support\Features;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Registrazione dal sito (§15.2).
 *
 * **La leva per registrarsi è la notifica, non il salvataggio** (§15.1): la
 * pagina lo dice, e non promette di custodire i salvataggi come se senza
 * account andassero persi — da anonimi restano nel browser e funzionano.
 *
 * Chi arriva qui dal riquadro del terzo salvataggio porta con sé le date nel
 * `localStorage`: la migrazione la chiede il browser subito dopo l'accesso
 * (`POST /salvataggi/unisci`), non questo modulo, così vale anche per chi
 * l'account ce l'aveva già e ha salvato da sloggato.
 */
final class RegisterController extends Controller
{
    public function create(AuthEntryRequest $request): View
    {
        $request->rememberDestination(freshEntry: true);

        return view('account.register', [
            'meta' => new PageMeta(
                title: __('account.register.title'),
                heading: __('account.register.title'),
                description: __('account.register.lead'),
                indexable: false,
            ),
        ]);
    }

    /**
     * La risposta è la stessa che l'indirizzo sia nuovo o già registrato
     * (D64): la pagina «controlla la posta», senza sessione. Si entra dal
     * collegamento che arriva per posta, che conferma l'indirizzo; aperto in
     * questo browser lascia valida la password scelta qui
     * (`RequestRegistration`, `MagicLinkController::login()`).
     */
    public function store(RegisterRequest $request, RequestRegistration $registration): RedirectResponse
    {
        [$user, $registrante] = $registration->account([
            'name' => $request->string('name')->value(),
            'first_name' => $request->validated('first_name'),
            'last_name' => $request->validated('last_name'),
            'email' => (string) $request->validated('email'),
            'password' => (string) $request->validated('password'),
            /*
             * Con la newsletter spenta la casella non compare nel modulo: un
             * `marketing_opt_in=1` inviato lo stesso è un campo scritto a mano
             * in una richiesta, non un consenso raccolto.
             */
            'marketing_opt_in' => Features::newsletterActive() && $request->boolean('marketing_opt_in'),
        ]);

        /* «Resta collegato» solo se spuntato, come all'accesso con password:
           vale quando il collegamento si apre in questo browser. */
        $registration->sendFromWeb($user, $registrante, $request->session(), $request->boolean('remember'));

        return redirect()
            ->route('account.register.sent')
            ->with('status', __('account.register.sent'));
    }

    /** «Controlla la posta»: la stessa pagina per ogni indirizzo. */
    public function sent(): View
    {
        return view('account.register-sent', [
            'meta' => new PageMeta(
                title: __('account.register.sent_title'),
                heading: __('account.register.sent_title'),
                description: __('account.register.sent_lead', ['minutes' => config()->integer('account.magic_link_minutes')]),
                indexable: false,
            ),
        ]);
    }
}
