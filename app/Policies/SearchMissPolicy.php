<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\SearchMiss;
use App\Models\User;

/**
 * Il registro delle ricerche senza risultati lo legge chi cura il catalogo:
 * è un elenco di cose da aggiungere, quindi segue il permesso sugli eventi.
 */
class SearchMissPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user?->can(Permission::ModerateEvents->value) ?? false;
    }

    public function view(?User $user, SearchMiss $miss): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SearchMiss $miss): bool
    {
        return false;
    }

    public function delete(User $user, SearchMiss $miss): bool
    {
        return $user->can(Permission::ModerateEvents->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Permission::ModerateEvents->value);
    }
}
