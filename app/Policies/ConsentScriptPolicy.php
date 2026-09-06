<?php

namespace App\Policies;

use App\Models\ConsentScript;
use App\Models\User;

class ConsentScriptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function view(User $user, ConsentScript $script): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ConsentScript $script): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, ConsentScript $script): bool
    {
        return $this->viewAny($user);
    }
}
