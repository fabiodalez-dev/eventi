<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ApplicationStatus;
use App\Enums\Permission;
use App\Models\User;
use App\Models\VenueApplication;

class VenueApplicationPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(User $user, VenueApplication $application): bool
    {
        return $application->user_id === $user->id || $user->can(Permission::ViewVenueApplications->value);
    }

    /**
     * Chiunque, anche un ospite, può proporre l'accreditamento di un locale
     * (§3 del piano — "Guest: proporre eventi, segnalare errori").
     */
    public function create(?User $user): bool
    {
        return true;
    }

    /**
     * Approvare o rifiutare è sempre una decisione della redazione, mai
     * dell'autore della richiesta.
     */
    public function update(User $user, VenueApplication $application): bool
    {
        return $user->can(Permission::ReviewVenueApplications->value);
    }

    /**
     * L'autore può ritirare la propria richiesta finché è in attesa; una
     * volta esaminata resta solo allo staff.
     */
    public function delete(User $user, VenueApplication $application): bool
    {
        if ($application->user_id === $user->id && $application->status === ApplicationStatus::Pending) {
            return true;
        }

        return $user->can(Permission::ReviewVenueApplications->value);
    }
}
