<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CommunityComment;
use App\Models\User;

final class CommunityCommentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isEditorialStaff();
    }

    public function update(User $user, CommunityComment $record): bool
    {
        return $user->isEditorialStaff() || ($user->id === $record->user_id && $user->isWhatsappVerified());
    }

    /**
     * Chi scrive il post modera la propria discussione: può togliere anche i commenti
     * altrui, ma solo finché la sua verifica è valida. Revocato o sospeso, conserva
     * soltanto il diritto di togliere ciò che ha scritto lui.
     */
    public function delete(User $user, CommunityComment $record): bool
    {
        return $user->isEditorialStaff() || $user->id === $record->user_id
            || ($user->id === $record->post->user_id && $user->isWhatsappVerified());
    }

    public function create(User $user): bool
    {
        return $user->isWhatsappVerified();
    }
}
