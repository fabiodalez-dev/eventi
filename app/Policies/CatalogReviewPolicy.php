<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CatalogReview;
use App\Models\User;
use App\Services\Reviews\CatalogReviews;

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

    /** Rispondere in pubblico a nome del locale o dell'organizzatore recensito. */
    public function reply(User $user, CatalogReview $review): bool
    {
        return app(CatalogReviews::class)->canReply($user, $review);
    }

    /** Togliere una risposta: chi può scriverla, e la redazione. */
    public function removeReply(User $user, CatalogReview $review): bool
    {
        return $this->viewAny($user) || $this->reply($user, $review);
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
