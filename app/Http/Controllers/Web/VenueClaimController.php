<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\CreateVenueApplication;
use App\DTOs\PageMeta;
use App\Enums\ApplicationKind;
use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\StoreVenueApplicationRequest;
use App\Models\Venue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * `/locali/{venue}/rivendica`: «questo locale a catalogo è mio».
 *
 * Il catalogo nasce anche da importazioni e dalla redazione, quindi molti
 * locali esistono prima che il loro gestore sappia di esistere qui. Finora
 * chi lo scopriva poteva solo compilare «registra il tuo locale» e creare un
 * doppione. La rivendicazione riusa la stessa coda e la stessa approvazione,
 * con il locale già agganciato: approvare vuol dire consegnare le chiavi,
 * non creare una seconda scheda.
 */
final class VenueClaimController extends Controller
{
    use InteractsWithCity;

    public function create(Venue $venue): View
    {
        $this->claimable($venue);

        return view('venues.apply', [
            'city' => $this->city(),
            'types' => [],
            'claim' => $venue,
            'meta' => new PageMeta(
                title: __('forms.claim.title'),
                heading: __('forms.claim.title'),
                description: __('forms.claim.lead', ['venue' => $venue->name]),
                indexable: false,
            ),
        ]);
    }

    public function store(StoreVenueApplicationRequest $request, Venue $venue, CreateVenueApplication $action): RedirectResponse
    {
        $this->claimable($venue);
        $data = $request->validated();
        $userId = $request->user()?->getKey();

        $action->handle([
            'venue_name' => $venue->name,
            'type' => $venue->type->value,
            'address' => $venue->address,
            'contact_name' => (string) $data['contact_name'],
            'contact_role' => isset($data['contact_role']) ? (string) $data['contact_role'] : null,
            'contact_phone' => isset($data['contact_phone']) ? (string) $data['contact_phone'] : null,
            'contact_email' => (string) $data['contact_email'],
            'message' => isset($data['message']) ? (string) $data['message'] : null,
        ], is_int($userId) ? $userId : null, ApplicationKind::Claim, (int) $venue->getKey());

        return to_route('venues.claim.create', $venue)->with('status', __('forms.claim.received'));
    }

    /**
     * Si rivendica un locale pubblico che nessuno gestisce ancora. Un locale
     * con un referente non si rivendica: se è un errore, lo dice la redazione.
     */
    private function claimable(Venue $venue): void
    {
        abort_unless($venue->status === VenueStatus::Approved, 404);
        abort_if($venue->owners()->exists(), 404, __('forms.claim.already_managed'));
    }
}
