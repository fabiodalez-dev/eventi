<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EventStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Policies\Concerns\ScopesToVenueMembership;

class EventPolicy
{
    use ScopesToVenueMembership;

    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Un evento pubblicato è pubblico. Bozza, in attesa, rifiutato o
     * archiviato sono visibili solo a chi gestisce il locale o allo staff.
     */
    public function view(?User $user, Event $event): bool
    {
        if ($event->status === EventStatus::Published) {
            return true;
        }

        return $user !== null && $this->canActOnEvent($user, $event, Permission::ViewEvents);
    }

    /**
     * A differenza delle altre abilità, `create` riceve il locale per cui si
     * vuole creare l'evento: senza, non c'è alcun `venue_id` da verificare
     * prima che l'evento esista. Si invoca con
     * `Gate::authorize('create', [Event::class, $venue])`.
     *
     * Il locale è **facoltativo** perché il pannello di redazione chiede il
     * permesso prima di sapere dove si terrà l'evento — e perché un evento
     * può non avere alcun locale (`venue_id` nullo, luogo libero). Senza
     * locale la domanda diventa "questa persona può creare eventi in
     * assoluto?", e la risposta è riservata allo staff globale: un referente
     * o un collaboratore crea sempre *per* un locale preciso, mai in astratto.
     */
    public function create(User $user, ?Venue $venue = null): bool
    {
        if ($venue === null) {
            return $this->isGlobalStaff($user) && $user->can(Permission::CreateEvents->value);
        }

        return $this->canActOnVenue($user, $venue->id, Permission::CreateEvents->value);
    }

    public function update(User $user, Event $event): bool
    {
        return $this->canActOnEvent($user, $event, Permission::UpdateEvents);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->canActOnEvent($user, $event, Permission::DeleteEvents);
    }

    public function restore(User $user, Event $event): bool
    {
        return $this->canActOnEvent($user, $event, Permission::DeleteEvents);
    }

    public function forceDelete(User $user, Event $event): bool
    {
        // Cancellazione permanente: mai al gestore del locale, solo allo staff globale.
        return $this->isGlobalStaff($user) && $user->can(Permission::DeleteEvents->value);
    }

    /**
     * Un locale con `auto_publish` pubblica da sé; altrimenti serve la
     * redazione (`events.moderate`) — è la semantica dichiarata dal campo
     * `venues.auto_publish` nello schema.
     */
    public function publish(User $user, Event $event): bool
    {
        if ($this->canActOnEvent($user, $event, Permission::PublishEvents)) {
            return $this->isGlobalStaff($user) || $event->venue?->auto_publish === true;
        }

        return $user->can(Permission::ModerateEvents->value);
    }

    /**
     * Approvazione, rifiuto, messa in evidenza: azione di redazione, non di
     * gestione ordinaria dell'evento da parte del locale.
     */
    public function moderate(User $user, Event $event): bool
    {
        return $user->can(Permission::ModerateEvents->value);
    }

    public function feature(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
    }

    /**
     * Un evento senza `venue_id` (location libera, non un locale registrato)
     * non ha alcuna pivot `venue_user` da verificare: resta gestibile solo
     * dallo staff globale con il permesso richiesto.
     */
    private function canActOnEvent(User $user, Event $event, Permission $permission): bool
    {
        if ($event->venue_id === null) {
            return $this->isGlobalStaff($user) && $user->can($permission->value);
        }

        return $this->canActOnVenue($user, $event->venue_id, $permission->value);
    }
}
