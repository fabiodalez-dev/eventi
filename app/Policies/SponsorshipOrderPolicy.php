<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Organizer;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Models\Venue;

/**
 * Chi decide sugli ordini: le stesse persone delle abilitazioni. Chi ordina
 * passa da `purchase`, dentro il pannello che lo limita gia' al proprio
 * locale o organizzatore.
 */
class SponsorshipOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
    }

    public function view(User $user, SponsorshipOrder $order): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SponsorshipOrder $order): bool
    {
        return false;
    }

    public function delete(User $user, SponsorshipOrder $order): bool
    {
        return false;
    }

    public function decide(User $user, SponsorshipOrder $order): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Chi puo' spendere a nome di un committente: il proprietario del locale
     * (ruolo `owner` nella pivot), o il proprietario dell'organizzatore. Un
     * collaboratore cura gli eventi, non firma acquisti.
     */
    public function purchase(User $user, Venue|Organizer $sponsor): bool
    {
        return $sponsor instanceof Venue
            ? $user->isOwnerOfVenue((int) $sponsor->getKey())
            : (int) $sponsor->owner_id === (int) $user->getKey();
    }
}
