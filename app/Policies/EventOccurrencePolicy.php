<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EventStatus;
use App\Enums\Permission;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Policies\Concerns\ScopesToVenueMembership;

/**
 * Le occorrenze non hanno un `venue_id` proprio: appartengono a un evento,
 * che appartiene a un locale. L'isolamento fra locali (§18 scenario F) passa
 * sempre dal `venue_id` dell'evento padre, mai da un dato copiato qui.
 */
class EventOccurrencePolicy
{
    use ScopesToVenueMembership;

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, EventOccurrence $occurrence): bool
    {
        if ($occurrence->event->status === EventStatus::Published) {
            return true;
        }

        return $user !== null && $this->canActOnOccurrenceVenue($user, $occurrence, Permission::ViewEvents);
    }

    /**
     * Riceve l'evento padre per lo stesso motivo di `EventPolicy::create()`:
     * senza, non c'è un `venue_id` da verificare prima che l'occorrenza
     * esista. Si invoca con `Gate::authorize('create', [EventOccurrence::class, $event])`.
     *
     * L'evento è facoltativo perché il pannello di redazione chiede il
     * permesso prima di conoscere la riga a cui si applica; senza evento la
     * domanda è "questa persona può aggiungere date in assoluto?", e la
     * risposta resta allo staff globale — chi gestisce un locale aggiunge
     * date sempre a un evento preciso, mai in astratto.
     */
    public function create(User $user, ?Event $event = null): bool
    {
        if ($event === null) {
            return $this->isGlobalStaff($user) && $user->can(Permission::CreateEvents->value);
        }

        return $this->canActOnEventVenue($user, $event, Permission::CreateEvents);
    }

    public function update(User $user, EventOccurrence $occurrence): bool
    {
        return $this->canActOnOccurrenceVenue($user, $occurrence, Permission::UpdateEvents);
    }

    public function delete(User $user, EventOccurrence $occurrence): bool
    {
        return $this->canActOnOccurrenceVenue($user, $occurrence, Permission::DeleteEvents);
    }

    private function canActOnOccurrenceVenue(User $user, EventOccurrence $occurrence, Permission $permission): bool
    {
        return $this->canActOnEventVenue($user, $occurrence->event, $permission);
    }

    private function canActOnEventVenue(User $user, Event $event, Permission $permission): bool
    {
        if ($event->venue_id === null) {
            return $this->isGlobalStaff($user) && $user->can($permission->value);
        }

        return $this->canActOnVenue($user, $event->venue_id, $permission->value);
    }
}
