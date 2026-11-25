<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Actions\LeaveVenueAction;
use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\ImportSource;
use App\Models\Organizer;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Gate;

/**
 * Che cosa può fare una persona su un locale o un'organizzazione, area per
 * area, per l'app di gestione.
 *
 * Nessuna regola nuova: ogni chiave rifà la domanda che il pannello fa per la
 * stessa pagina o azione, alla stessa policy. L'app la usa per mostrare o
 * nascondere le voci; il server ricontrolla comunque ogni azione.
 *
 * Le aree che nel pannello dell'organizzazione non esistono (collaboratori,
 * import del calendario, grafiche social, canali propri delle statistiche,
 * uscita) valgono `false` per l'organizzazione.
 */
final class ManagementPermissions
{
    /** Le chiavi, nell'ordine in cui le documenta il contratto. */
    public const KEYS = [
        'events_create', 'events_publish_directly', 'ticketing_manage', 'profile_update', 'collaborators_manage',
        'import_manage', 'analytics_view', 'analytics_channels', 'sponsorships_view', 'sponsorships_purchase',
        'social_use', 'comments_moderate', 'reviews_reply', 'leave',
    ];

    /** @return array<string, bool> */
    public function for(User $user, Venue|Organizer $subject): array
    {
        $gate = Gate::forUser($user);

        if ($subject instanceof Venue) {
            $owner = $user->isOwnerOfVenue((int) $subject->getKey());

            return [
                // `EventPolicy::create` con il locale, come «Nuovo evento».
                'events_create' => $gate->allows('create', [Event::class, $subject]),
                // `EventPolicy::publish` su un evento nuovo del locale: auto_publish e locale approvato.
                'events_publish_directly' => $gate->allows('publish', $this->draftAt($subject)),
                // `BookingPolicy::manage`: il referente di un locale senza provvedimenti (o lo staff).
                'ticketing_manage' => $owner || $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]),
                // `VenueProfile::canAccess`.
                'profile_update' => $gate->allows('update', $subject),
                // `Collaborators::canAccess`.
                'collaborators_manage' => $gate->allows('manageCollaborators', $subject),
                // `CalendarImport::canAccess`.
                'import_manage' => $gate->allows('create', [ImportSource::class, $subject]),
                'analytics_view' => true,
                // `EventAnalyticsPage::canManageChannels`.
                'analytics_channels' => $gate->allows('update', $subject),
                'sponsorships_view' => true,
                // `SponsorshipsPage::canPurchase`.
                'sponsorships_purchase' => $gate->allows('purchase', [SponsorshipOrder::class, $subject]),
                'social_use' => true,
                // `EventCommentPolicy::hide`: ogni membro del locale.
                'comments_moderate' => true,
                // `CatalogReviews::canReply`: ogni membro del locale (la recensione deve essere pubblicata).
                'reviews_reply' => true,
                // `VenueAccess`: tutti, tranne l'ultimo referente.
                'leave' => ! app(LeaveVenueAction::class)->isLastOwner($subject, $user),
            ];
        }

        return [
            'events_create' => $gate->allows('create', [Event::class, $subject]),
            // L'organizzazione non pubblica mai da sé: «Invia alla redazione».
            'events_publish_directly' => false,
            // `BookingPolicy::manage`: chi gestisce l'organizzatore dell'evento.
            'ticketing_manage' => true,
            // `Organizer\Pages\Profile::canAccess`: solo `owner_id`.
            'profile_update' => (int) $subject->owner_id === (int) $user->getKey(),
            'collaborators_manage' => false,
            'import_manage' => false,
            'analytics_view' => true,
            'analytics_channels' => false,
            'sponsorships_view' => true,
            'sponsorships_purchase' => $gate->allows('purchase', [SponsorshipOrder::class, $subject]),
            'social_use' => false,
            'comments_moderate' => true,
            'reviews_reply' => true,
            'leave' => false,
        ];
    }

    /** Un evento in bozza del locale, mai salvato: serve solo a chiedere alla policy. */
    private function draftAt(Venue $venue): Event
    {
        $event = new Event;
        $event->forceFill(['venue_id' => $venue->getKey(), 'status' => EventStatus::Draft]);
        $event->setRelation('venue', $venue);

        return $event;
    }
}
