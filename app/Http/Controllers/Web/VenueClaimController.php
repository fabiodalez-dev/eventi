<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Account\RequestRegistration;
use App\Actions\CreateVenueApplication;
use App\DTOs\PageMeta;
use App\Enums\ApplicationKind;
use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\StoreVenueApplicationRequest;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/locali/{venue}/rivendica`: «questo locale a catalogo è mio».
 *
 * Il catalogo nasce anche da importazioni e dalla redazione, quindi molti
 * locali esistono prima che il loro gestore sappia di esistere qui. Finora
 * chi lo scopriva poteva solo compilare «registra il tuo locale» e creare un
 * doppione. La rivendicazione riusa la stessa coda e la stessa approvazione,
 * con il locale già agganciato: approvare vuol dire consegnare le chiavi,
 * non creare una seconda scheda.
 *
 * Come la registrazione, parte sempre da un account: quello di chi è
 * collegato (nome ed email già scritti), o uno nuovo, e la richiesta entra in
 * coda con l'email confermata.
 */
final class VenueClaimController extends Controller
{
    use InteractsWithCity;

    public function create(Request $request, Venue $venue): View
    {
        $this->claimable($venue);
        StoreVenueApplicationRequest::restoreDraft($request, 'claim:'.$venue->getKey());

        return view('venues.apply', [
            'city' => $this->city(),
            'mode' => 'claim',
            'types' => [],
            'claim' => $venue,
            ...VenueApplicationController::accountState($request->user()),
            'meta' => new PageMeta(
                title: __('forms.claim.title'),
                heading: __('forms.claim.title'),
                description: __('forms.claim.lead', ['venue' => $venue->name]),
                indexable: false,
            ),
        ]);
    }

    public function store(StoreVenueApplicationRequest $request, Venue $venue, CreateVenueApplication $action, RequestRegistration $registration): RedirectResponse
    {
        $this->claimable($venue);
        $user = $request->user();
        $email = $request->applicantEmail();
        $returnTo = route('venues.claim.create', $venue);

        if ($action->limitReached($email, $user instanceof User ? $user : null, (int) $venue->getKey())) {
            return $user instanceof User
                ? back()->withInput()->withErrors(['form' => __('forms.claim.limit_reached')])
                : VenueApplicationController::sent($returnTo, 'check_email');
        }

        $account = $user instanceof User ? $user : $request->guestAccount($registration, $returnTo);
        $confirmed = $user instanceof User && $user->hasVerifiedEmail();
        $data = $request->validated();

        $action->handle([
            'venue_name' => $venue->name,
            'type' => $venue->type->value,
            'address' => $venue->address,
            'contact_name' => (string) $data['contact_name'],
            'contact_role' => isset($data['contact_role']) ? (string) $data['contact_role'] : null,
            'contact_phone' => isset($data['contact_phone']) ? (string) $data['contact_phone'] : null,
            'contact_email' => $email,
            'message' => (string) $data['message'],
        ], (int) $account->getKey(), ApplicationKind::Claim, (int) $venue->getKey(), $confirmed);

        return VenueApplicationController::sent($returnTo, $confirmed ? 'queued' : ($user instanceof User ? 'verify' : 'check_email'));
    }

    /** «Ho già un account»: quanto scritto resta, si accede e si torna qui. */
    public function draft(Request $request, Venue $venue): RedirectResponse
    {
        $this->claimable($venue);
        StoreVenueApplicationRequest::saveDraft($request, 'claim:'.$venue->getKey());

        return VenueApplicationController::toLogin($request, route('venues.claim.create', $venue));
    }

    /**
     * Si rivendica un locale pubblico che nessuno gestisce ancora. Un locale
     * con un referente non si rivendica: se è un errore, lo dice la redazione.
     * La scheda pubblica resta in cache fino a mezz'ora dopo un'approvazione e
     * può ancora mostrare l'invito: il controllo vero è questo.
     */
    private function claimable(Venue $venue): void
    {
        if (! $venue->isClaimable()) {
            abort(404, $venue->status === VenueStatus::Approved ? (string) __('forms.claim.already_managed') : '');
        }
    }
}
