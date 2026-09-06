<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SponsorshipGrant;
use App\Models\User;

class SponsorshipGrantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, SponsorshipGrant $grant): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, SponsorshipGrant $grant): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, SponsorshipGrant $grant): bool
    {
        return false;
    }
}
