<?php

declare(strict_types=1);

namespace App\Support\VenueApplications;

use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\VenueStatus;
use App\Models\User;
use App\Models\VenueApplication;
use Filament\Facades\Filament;

/**
 * Lo stato di una richiesta di registrazione o rivendicazione come lo vede
 * chi l'ha mandata: la pagina «Le mie richieste» e `GET /v1/me/venue-applications`
 * dicono la stessa cosa perché la chiedono entrambe qui.
 *
 * Il pannello si apre solo se l'account gestisce davvero il locale o
 * l'organizzazione; la scheda pubblica solo se è visibile a tutti. Gli id
 * gestiti si passano già letti, una volta per elenco.
 */
final class ApplicationState
{
    /**
     * @param  list<int|string>  $venueIds  i locali che l'account gestisce
     * @param  list<int|string>  $organizerIds  le organizzazioni che l'account gestisce
     * @return array{state: 'awaiting'|'pending'|'approved'|'rejected', panel: string|null, public: string|null, next: string, confirm: bool}
     */
    public static function describe(VenueApplication $application, User $user, array $venueIds, array $organizerIds): array
    {
        $kind = $application->kind();

        $state = match (true) {
            $application->isAwaitingEmail() => 'awaiting',
            $application->status === ApplicationStatus::Approved => 'approved',
            $application->status === ApplicationStatus::Rejected => 'rejected',
            default => 'pending',
        };

        $panel = null;
        $public = null;
        if ($state === 'approved') {
            if ($kind === ApplicationKind::Organizer && $application->organizer !== null) {
                $organizer = $application->organizer;
                $panel = in_array($organizer->getKey(), $organizerIds, false) ? Filament::getPanel('organizer')->getUrl($organizer) : null;
                $public = $organizer->is_active ? route('organizers.show', $organizer) : null;
            } elseif ($application->venue !== null) {
                $venue = $application->venue;
                $panel = in_array($venue->getKey(), $venueIds, false) ? Filament::getPanel('venue')->getUrl($venue) : null;
                $public = $venue->status === VenueStatus::Approved ? route('venues.show', $venue) : null;
            }
        }

        $next = match ($state) {
            'awaiting' => $user->hasVerifiedEmail()
                ? __('venue_applications.account.next.awaiting_confirm')
                : __('venue_applications.account.next.awaiting_email', ['email' => $user->email]),
            'pending' => __('venue_applications.account.next.pending'),
            'approved' => $panel !== null
                ? __('venue_applications.account.next.approved_'.($kind === ApplicationKind::Organizer ? 'organizer' : 'venue'))
                : __('venue_applications.account.next.approved_invite'),
            default => __('venue_applications.account.next.rejected'),
        };

        return [
            'state' => $state,
            'panel' => $panel,
            'public' => $public,
            'next' => $next,
            'confirm' => $state === 'awaiting' && $user->hasVerifiedEmail(),
        ];
    }
}
