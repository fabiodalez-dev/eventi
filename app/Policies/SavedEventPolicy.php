<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SavedEvent;
use App\Models\User;

/**
 * Nessun permesso in catalogo: salvare un evento è un'azione ordinaria di
 * ogni utente registrato, l'unica regola è essere il proprietario del record.
 */
class SavedEventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SavedEvent $savedEvent): bool
    {
        return $savedEvent->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SavedEvent $savedEvent): bool
    {
        return $savedEvent->user_id === $user->id;
    }

    public function delete(User $user, SavedEvent $savedEvent): bool
    {
        return $savedEvent->user_id === $user->id;
    }
}
