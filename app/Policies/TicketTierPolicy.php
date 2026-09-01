<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EventStatus;
use App\Enums\Permission;
use App\Models\Event;
use App\Models\TicketTier;
use App\Models\User;
use App\Policies\Concerns\ScopesToVenueMembership;

/**
 * Una fascia di prezzo non ha un `venue_id` proprio: appartiene a un evento,
 * che appartiene a un locale. Come per le occorrenze, l'isolamento fra locali
 * (§18 scenario F) passa sempre dal `venue_id` dell'evento padre — mai da un
 * dato copiato qui, che sarebbe una seconda verità da tenere allineata.
 */
class TicketTierPolicy
{
    use ScopesToVenueMembership;

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, TicketTier $tier): bool
    {
        if ($tier->event->status === EventStatus::Published) {
            return true;
        }

        return $user !== null && $this->canActOnEventVenue($user, $tier->event, Permission::ViewEvents);
    }

    /**
     * L'evento è facoltativo per la stessa ragione di
     * `EventOccurrencePolicy::create()`: il pannello chiede il permesso prima
     * di conoscere la riga. Senza evento la domanda diventa «può creare fasce
     * in assoluto?», e la risposta resta allo staff globale.
     */
    public function create(User $user, ?Event $event = null): bool
    {
        if ($event === null) {
            return $this->isGlobalStaff($user) && $user->can(Permission::CreateEvents->value);
        }

        return $this->canActOnEventVenue($user, $event, Permission::CreateEvents);
    }

    public function update(User $user, TicketTier $tier): bool
    {
        return $this->canActOnEventVenue($user, $tier->event, Permission::UpdateEvents);
    }

    public function delete(User $user, TicketTier $tier): bool
    {
        return $this->canActOnEventVenue($user, $tier->event, Permission::DeleteEvents);
    }

    private function canActOnEventVenue(User $user, Event $event, Permission $permission): bool
    {
        if ($event->venue_id === null) {
            return $this->isGlobalStaff($user) && $user->can($permission->value);
        }

        return $this->canActOnVenue($user, $event->venue_id, $permission->value);
    }
}
