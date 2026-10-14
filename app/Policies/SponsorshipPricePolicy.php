<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SponsorshipPrice;
use App\Models\User;

class SponsorshipPricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
    }

    public function view(User $user, SponsorshipPrice $price): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, SponsorshipPrice $price): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, SponsorshipPrice $price): bool
    {
        return $this->viewAny($user);
    }
}
