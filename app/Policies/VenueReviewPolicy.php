<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VenueReview;

class VenueReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    public function view(User $user, VenueReview $review): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, VenueReview $review): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, VenueReview $review): bool
    {
        return false;
    }
}
