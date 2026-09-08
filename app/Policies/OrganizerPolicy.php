<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Organizer;
use App\Models\User;

class OrganizerPolicy
{
    private function staff(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
    }

    public function viewAny(User $user): bool
    {
        return $this->staff($user);
    }

    public function view(User $user, Organizer $organizer): bool
    {
        return $this->staff($user);
    }

    public function create(User $user): bool
    {
        return $this->staff($user);
    }

    public function update(User $user, Organizer $organizer): bool
    {
        return $this->staff($user);
    }

    public function delete(User $user, Organizer $organizer): bool
    {
        return false;
    }
}
