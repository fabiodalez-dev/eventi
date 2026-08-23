<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Follow;
use App\Models\User;

/**
 * Nessun permesso in catalogo: seguire un locale, un tag o una categoria è
 * un'azione ordinaria di ogni utente registrato, l'unica regola è essere il
 * proprietario del record.
 */
class FollowPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Follow $follow): bool
    {
        return $follow->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Follow $follow): bool
    {
        return $follow->user_id === $user->id;
    }

    public function delete(User $user, Follow $follow): bool
    {
        return $follow->user_id === $user->id;
    }
}
