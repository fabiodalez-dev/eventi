<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\CreateVenueApplication;
use App\Actions\SubmitVenueApplication;
use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\StoreOrganizerApplicationRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/registra-la-tua-organizzazione`: la porta per chi organizza senza una
 * sede. Associazioni, collettivi, rassegne itineranti: finora avevano solo
 * «registra il tuo locale», che chiede un indirizzo che non hanno.
 */
final class OrganizerApplicationController extends Controller
{
    use InteractsWithCity;

    public function create(Request $request): View
    {
        $city = $this->city();

        return view('venues.apply', [
            'city' => $city,
            'mode' => 'organizer',
            'types' => [],
            ...VenueApplicationController::accountState($request->user()),
            'meta' => new PageMeta(
                title: __('forms.organizer_application.title'),
                heading: __('forms.organizer_application.title'),
                description: __('forms.organizer_application.lead', ['city' => $city->name]),
                canonical: route('organizer-applications.create'),
            ),
        ]);
    }

    public function store(StoreOrganizerApplicationRequest $request, CreateVenueApplication $action, SubmitVenueApplication $submit): RedirectResponse
    {
        $user = $request->user();
        $userId = $user?->getKey();
        $email = $request->applicantEmail();
        $returnTo = route('organizer-applications.create');

        if ($action->limitReached($email, $user instanceof User ? $user : null)) {
            return $user instanceof User
                ? back()->withInput()->withErrors(['form' => __('forms.application.limit_reached')])
                : VenueApplicationController::sent($returnTo, 'organizer');
        }

        $submit->organizer($request->validated(), $email, is_int($userId) ? $userId : null);

        /* Un organizzatore entra in coda subito, anche da ospite: l'account
           lo crea l'approvazione, che manda il collegamento per la password
           all'indirizzo scritto (e lo conferma così). */
        return VenueApplicationController::sent($returnTo, 'organizer');
    }
}
