<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EventCollection;
use App\Models\User;

/**
 * Una raccolta è di chi l'ha fatta. Chiunque la vede dal link solo se è
 * pubblica; modificarla, aggiungere o togliere date, cancellarla: solo chi
 * ne è l'autore.
 */
class EventCollectionPolicy
{
    public function view(?User $user, EventCollection $collection): bool
    {
        return $collection->is_public || ($user !== null && (int) $collection->user_id === (int) $user->getKey());
    }

    public function update(User $user, EventCollection $collection): bool
    {
        return (int) $collection->user_id === (int) $user->getKey();
    }

    public function delete(User $user, EventCollection $collection): bool
    {
        return $this->update($user, $collection);
    }
}
