<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VenueApplication;
use App\Support\VenueApplications\ApplicationPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /v1/me/venue-applications`: «Le mie richieste» per l'app, con gli stessi
 * stati e gli stessi testi della pagina `/le-mie-richieste`
 * (`Web\Account\VenueApplicationsController`).
 *
 * Solo le richieste dell'account collegato, comprese quelle che aspettano la
 * conferma dell'email (via lo scope globale del modello). Le note interne
 * della redazione, i recapiti del referente e l'immagine non escono mai.
 *
 * Le azioni («Conferma e invia», «Elimina», i moduli) l'app le fa con le
 * rotte di P13 (`ApplicationController`); `manage_url`, `panel_url` e gli
 * indirizzi dei moduli restano per le versioni dell'app già installate.
 */
final class VenueApplicationController extends Controller
{
    /**
     * Le mie richieste.
     *
     * Registrazioni e rivendicazioni di locali e organizzazioni mandate con
     * questo account, con lo stato e il testo «cosa succede adesso».
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $page = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        $applications = VenueApplication::query()
            ->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)
            ->where('user_id', $user->getKey())
            ->with(['venue', 'organizer'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(20, page: (int) ($page['page'] ?? 1));

        $venues = $user->venues()->pluck('venues.id')->all();
        $organizers = $user->managedOrganizers()->pluck('organizers.id')->all();

        return response()->json([
            'data' => collect($applications->items())
                ->map(fn (VenueApplication $application): array => ApplicationPayload::item($application, $user, $venues, $organizers))
                ->values(),
            'meta' => [
                'next_page' => $applications->hasMorePages() ? $applications->currentPage() + 1 : null,
                'manage_url' => route('account.applications'),
                'new_venue_url' => route('venue-applications.create'),
                'new_organizer_url' => route('organizer-applications.create'),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
