<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EventFeature;
use App\Models\User;

class EventFeaturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function view(User $user, EventFeature $feature): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, EventFeature $feature): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, EventFeature $feature): bool
    {
        return $this->viewAny($user) && ! $feature->is_system;
    }
}
