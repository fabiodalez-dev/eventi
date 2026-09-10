<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\EventSubmission;
use App\Models\User;

class EventSubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin, UserRole::Moderator])
            && $user->can(Permission::ModerateEvents->value);
    }

    public function view(User $user, EventSubmission $submission): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, EventSubmission $submission): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, EventSubmission $submission): bool
    {
        return false;
    }
}
