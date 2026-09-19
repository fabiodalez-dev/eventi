<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RideReview;
use App\Models\User;
use App\Services\Carpool\RideReviews;

class RideReviewPolicy
{
    public function view(User $user, RideReview $review): bool
    {
        return $user->id === $review->user_id || (app(RideReviews::class)->reader($user, $review->driver) && app(RideReviews::class)->published($review->driver)->whereKey($review->id)->exists());
    }
}
