<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;

/**
 * L'anagrafica degli account. A differenza dei contenuti non esiste alcun
 * `venue_id` da confrontare: la domanda è sempre e solo "chi amministra le
 * persone?", e la risposta è lo staff che ha `users.manage` — cioè
 * amministratore e amministratore di sistema, mai il moderatore.
 *
 * Due clausole valgono anche per chi quel permesso ce l'ha:
 *
 * - **nessuno cancella se stesso**, perché il pannello si chiuderebbe sotto
 *   i piedi di chi ha appena premuto il pulsante;
 * - **nessuno tocca un amministratore di sistema** se non è a sua volta
 *   amministratore di sistema: senza questa riga un amministratore potrebbe
 *   entrare nei panni del ruolo che gli sta sopra e prendersene i permessi.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isEditorialStaff();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isEditorialStaff();
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    public function update(User $user, User $target): bool
    {
        return $user->can(Permission::ManageUsers->value)
            && $this->outranks($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->can(Permission::ManageUsers->value)
            && $user->isNot($target)
            && $this->outranks($user, $target);
    }

    public function restore(User $user, User $target): bool
    {
        return $this->delete($user, $target);
    }

    public function forceDelete(User $user, User $target): bool
    {
        return $user->hasRole(UserRole::SuperAdmin->value) && $user->isNot($target);
    }

    /**
     * Entrare nei panni di qualcuno serve a riprodurre ciò che quella persona
     * vede quando segnala un problema. Vale verso il basso e mai verso se
     * stessi: impersonare chi ha più poteri sarebbe una scala di privilegi.
     */
    public function impersonate(User $user, User $target): bool
    {
        return $user->can(Permission::ImpersonateUsers->value)
            && $user->isNot($target)
            && $this->outranks($user, $target)
            && ! $target->trashed();
    }

    private function outranks(User $user, User $target): bool
    {
        if ($user->hasRole(UserRole::SuperAdmin->value)) {
            return true;
        }

        return ! $target->hasRole(UserRole::SuperAdmin->value);
    }
}
