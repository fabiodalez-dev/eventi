<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Tag $tag): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageTags->value);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->can(Permission::ManageTags->value);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->can(Permission::ManageTags->value);
    }

    /**
     * Approvare un tag (`is_approved`) è una decisione più leggera della
     * gestione completa: il moderatore la prende senza poter rinominare o
     * unire i tag.
     */
    public function approve(User $user, Tag $tag): bool
    {
        return $user->can(Permission::ApproveTags->value) || $user->can(Permission::ManageTags->value);
    }
}
