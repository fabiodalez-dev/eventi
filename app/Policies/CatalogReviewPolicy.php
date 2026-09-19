<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CatalogReview;
use App\Models\User;

class CatalogReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function view(User $user, CatalogReview $review): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CatalogReview $review): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, CatalogReview $review): bool
    {
        return false;
    }
}
