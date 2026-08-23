<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\VenueStatus;
use App\Models\User;
use App\Models\Venue;
use App\Policies\Concerns\ScopesToVenueMembership;

class VenuePolicy
{
    use ScopesToVenueMembership;

    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Un locale approvato è pubblico. Le altre condizioni (bozza, in attesa,
     * sospeso, rifiutato) sono visibili solo a chi lo gestisce o allo staff.
     */
    public function view(?User $user, Venue $venue): bool
    {
        if ($venue->status === VenueStatus::Approved) {
            return true;
        }

        return $user !== null && $this->canActOnVenue($user, $venue->id, Permission::ViewVenues->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CreateVenues->value);
    }

    /**
     * Solo il referente del locale, mai il collaboratore: è la clausola
     * esplicita del piano ("l'editor non può modificare i dati sensibili
     * del locale").
     */
    public function update(User $user, Venue $venue): bool
    {
        return $this->canActOnVenue($user, $venue->id, Permission::UpdateVenues->value, requireOwner: true);
    }

    public function delete(User $user, Venue $venue): bool
    {
        // La cancellazione di un locale non è mai un'azione del referente:
        // resta allo staff globale ("il referente non può cancellare il locale").
        return $this->isGlobalStaff($user) && $user->can(Permission::DeleteVenues->value);
    }

    public function restore(User $user, Venue $venue): bool
    {
        return $this->isGlobalStaff($user) && $user->can(Permission::DeleteVenues->value);
    }

    public function forceDelete(User $user, Venue $venue): bool
    {
        return $this->isGlobalStaff($user) && $user->can(Permission::DeleteVenues->value);
    }

    /**
     * Approvazione, sospensione, rifiuto: azione di moderazione, non di
     * gestione ordinaria del locale.
     */
    public function moderate(User $user, Venue $venue): bool
    {
        return $user->can(Permission::ModerateVenues->value);
    }

    /**
     * Inviti e rimozioni dei collaboratori: solo il referente del locale
     * (mai l'editor) o lo staff globale.
     */
    public function manageCollaborators(User $user, Venue $venue): bool
    {
        return $this->canActOnVenue($user, $venue->id, Permission::ManageVenueCollaborators->value, requireOwner: true);
    }

    /**
     * Dati del referente e altre informazioni sensibili: mai visibili
     * all'editor (§3 del piano).
     */
    public function viewSensitiveData(User $user, Venue $venue): bool
    {
        return $this->canActOnVenue($user, $venue->id, Permission::ViewVenueSensitiveData->value, requireOwner: true);
    }
}
