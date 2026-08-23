<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\CreateVenueApplication;
use App\DTOs\PageMeta;
use App\Enums\VenueType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\StoreVenueApplicationRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * `/registra-il-tuo-locale` (§11.1).
 *
 * È la porta d'ingresso dei locali, cioè la leva di crescita del catalogo: il
 * modulo chiede il minimo per poter richiamare qualcuno e verificare che il
 * locale esista davvero.
 */
final class VenueApplicationController extends Controller
{
    use InteractsWithCity;

    public function create(): View
    {
        $city = $this->city();

        return view('venues.apply', [
            'city' => $city,
            'types' => VenueType::options(),
            'meta' => new PageMeta(
                title: __('forms.application.title'),
                heading: __('forms.application.title'),
                description: __('forms.application.lead', ['city' => $city->name]),
                canonical: route('venue-applications.create'),
            ),
        ]);
    }

    public function store(StoreVenueApplicationRequest $request, CreateVenueApplication $action): RedirectResponse
    {
        $data = $request->validated();
        $userId = $request->user()?->getKey();

        $action->handle([
            'venue_name' => (string) $data['venue_name'],
            'type' => isset($data['type']) ? (string) $data['type'] : null,
            'address' => isset($data['address']) ? (string) $data['address'] : null,
            'website' => isset($data['website']) ? (string) $data['website'] : null,
            'contact_name' => (string) $data['contact_name'],
            'contact_role' => isset($data['contact_role']) ? (string) $data['contact_role'] : null,
            'contact_phone' => isset($data['contact_phone']) ? (string) $data['contact_phone'] : null,
            'contact_email' => (string) $data['contact_email'],
            'message' => isset($data['message']) ? (string) $data['message'] : null,
        ], is_int($userId) ? $userId : null);

        return to_route('venue-applications.create')->with('status', __('forms.application.received'));
    }
}
