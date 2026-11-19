<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Enums\ApplicationKind;
use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VenueApplication;
use App\Support\Api\ApiDate;
use App\Support\VenueApplications\ApplicationState;
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
 * Le azioni («Conferma e invia», «Elimina», «Registra un locale») restano
 * pagine del sito: l'app apre `manage_url` o `panel_url` nel browser.
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
                ->map(fn (VenueApplication $application): array => $this->item($application, $user, $venues, $organizers))
                ->values(),
            'meta' => [
                'next_page' => $applications->hasMorePages() ? $applications->currentPage() + 1 : null,
                'manage_url' => route('account.applications'),
                'new_venue_url' => route('venue-applications.create'),
                'new_organizer_url' => route('organizer-applications.create'),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * @param  list<int|string>  $venues
     * @param  list<int|string>  $organizers
     * @return array<string, mixed>
     */
    private function item(VenueApplication $application, User $user, array $venues, array $organizers): array
    {
        $kind = $application->kind();
        ['state' => $state, 'panel' => $panel, 'public' => $public, 'next' => $next, 'confirm' => $confirm] = ApplicationState::describe($application, $user, $venues, $organizers);

        return [
            'id' => (int) $application->getKey(),
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'name' => (string) $application->venue_name,
            'type' => $kind === ApplicationKind::Organizer ? null : $application->type->value,
            'type_label' => $kind === ApplicationKind::Organizer ? null : $application->type->label(),
            'address' => $kind === ApplicationKind::Organizer ? null : $application->address,
            'state' => $state,
            'state_label' => __('venue_applications.account.state.'.$state),
            'next' => $next,
            'created_at' => ApiDate::attribute($application, 'created_at', 'UTC'),
            'reviewed_at' => in_array($state, ['approved', 'rejected'], true) ? ApiDate::instant($application->reviewed_at, 'UTC') : null,
            'can_confirm' => $confirm,
            'panel_url' => $panel,
            'public_url' => $public,
            'venue_slug' => $application->venue?->status === VenueStatus::Approved ? $application->venue->slug : null,
            'organizer_slug' => $application->organizer?->is_active ? $application->organizer->slug : null,
        ];
    }
}
