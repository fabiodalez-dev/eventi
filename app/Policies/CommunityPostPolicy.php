<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CommunityPost;
use App\Models\User;

final class CommunityPostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isEditorialStaff();
    }

    public function update(User $user, CommunityPost $record): bool
    {
        return $user->isEditorialStaff() || ($user->id === $record->user_id && $user->isWhatsappVerified());
    }

    public function delete(User $user, CommunityPost $record): bool
    {
        return $user->isEditorialStaff() || $user->id === $record->user_id;
    }

    public function create(User $user): bool
    {
        return $user->isWhatsappVerified();
    }
}
