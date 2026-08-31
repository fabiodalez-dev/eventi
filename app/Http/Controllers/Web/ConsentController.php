<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\RecordConsent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\StoreConsentRequest;
use App\Models\User;
use App\Support\Consent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Il consenso (§16).
 *
 * Come il cuore dei salvataggi (§15.1), risponde in due modi perché funziona in
 * due modi: il banner è un **modulo vero**, che senza JavaScript invia e
 * ricarica la pagina, e con JavaScript si chiude senza ricaricare niente.
 * È ciò che tiene il rifiuto a un solo click in entrambi i casi — un banner che
 * si chiude solo via script obbliga chi non lo esegue a convivere con una
 * striscia in fondo allo schermo per sempre.
 */
final class ConsentController extends Controller
{
    public function store(StoreConsentRequest $request, RecordConsent $record, Consent $consent): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        $state = $record->handle(
            $request->consentAction(),
            $request->acceptedCategories(),
            $user instanceof User ? (int) $user->getKey() : null,
        );

        $cookie = $consent->cookie($state);

        if ($request->expectsJson()) {
            return response()->json(['choices' => $state->choices])->withCookie($cookie);
        }

        return back(fallback: url('/'))
            ->with('status', __('consent.saved'))
            ->withCookie($cookie);
    }

    /**
     * La revoca (§16: una scelta deve poter essere ritirata con la stessa
     * facilità con cui è stata data). Togliere il cookie fa ricomparire il
     * banner alla pagina successiva: è la sola strada che riporta davvero alla
     * situazione di partenza, mentre un «rifiuto» registrato al posto della
     * revoca sarebbe un'altra scelta, non l'assenza di scelta.
     */
    public function destroy(Consent $consent): RedirectResponse
    {
        return back(fallback: url('/'))
            ->with('status', __('consent.revoked'))
            ->withCookie($consent->forget());
    }
}
