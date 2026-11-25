<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Organizer;
use App\Models\User;

class OrganizerPolicy
{
    private function staff(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
    }

    public function viewAny(User $user): bool
    {
        return $this->staff($user);
    }

    public function view(User $user, Organizer $organizer): bool
    {
        return $this->staff($user);
    }

    public function create(User $user): bool
    {
        return $this->staff($user);
    }

    public function update(User $user, Organizer $organizer): bool
    {
        return $this->staff($user);
    }

    /**
     * Il profilo pubblico dell'organizzazione (presentazione, sito, email,
     * modulo di contatto), dal pannello dell'organizzatore e dall'app: solo
     * chi l'ha creata (`owner_id`), e solo finché è attiva. I membri
     * gestiscono eventi e biglietteria, non la presentazione.
     *
     * È una regola distinta da `update`, che resta della redazione: lo staff
     * cambia l'organizzazione dall'amministrazione, il proprietario solo la
     * sua scheda pubblica.
     */
    public function updateProfile(User $user, Organizer $organizer): bool
    {
        return $organizer->is_active && (int) $organizer->owner_id === (int) $user->getKey();
    }

    public function delete(User $user, Organizer $organizer): bool
    {
        return false;
    }
}
