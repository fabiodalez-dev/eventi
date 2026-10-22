<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\CreateVenueApplication;
use App\DTOs\PageMeta;
use App\Enums\ApplicationKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\StoreOrganizerApplicationRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * `/registra-la-tua-organizzazione`: la porta per chi organizza senza una
 * sede. Associazioni, collettivi, rassegne itineranti: finora avevano solo
 * «registra il tuo locale», che chiede un indirizzo che non hanno.
 */
final class OrganizerApplicationController extends Controller
{
    use InteractsWithCity;

    public function create(): View
    {
        $city = $this->city();

        return view('venues.apply', [
            'city' => $city,
            'types' => [],
            'organizer' => true,
            'meta' => new PageMeta(
                title: __('forms.organizer_application.title'),
                heading: __('forms.organizer_application.title'),
                description: __('forms.organizer_application.lead', ['city' => $city->name]),
                canonical: route('organizer-applications.create'),
            ),
        ]);
    }

    public function store(StoreOrganizerApplicationRequest $request, CreateVenueApplication $action): RedirectResponse
    {
        $data = $request->validated();
        $userId = $request->user()?->getKey();

        $action->handle([
            'venue_name' => (string) $data['venue_name'],
            'website' => isset($data['website']) ? (string) $data['website'] : null,
            'contact_name' => (string) $data['contact_name'],
            'contact_role' => isset($data['contact_role']) ? (string) $data['contact_role'] : null,
            'contact_phone' => isset($data['contact_phone']) ? (string) $data['contact_phone'] : null,
            'contact_email' => (string) $data['contact_email'],
            'message' => isset($data['message']) ? (string) $data['message'] : null,
        ], is_int($userId) ? $userId : null, ApplicationKind::Organizer);

        return to_route('organizer-applications.create')->with('status', __('forms.organizer_application.received'));
    }
}
