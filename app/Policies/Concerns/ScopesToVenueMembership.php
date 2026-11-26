<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Verifica di appartenenza a un locale, riusata da ogni Policy che tocca un
 * contenuto di venue (§7 di AGENT-CONVENTIONS, §18 scenario F del piano):
 * un owner o un editor del locale A non deve poter leggere, modificare o
 * cancellare nulla del locale B, nemmeno manipolando URL o ID.
 *
 * Un solo metodo — `canActOnVenue()` — decide sempre allo stesso modo se
 * l'utente può agire su un locale specifico, cosicché la regola non venga
 * riscritta con sfumature diverse in ogni Policy.
 */
trait ScopesToVenueMembership
{
    /**
     * Admin, super admin e moderatore operano su qualunque locale, mai su
     * uno solo: nessuno dei tre ha una riga nella pivot `venue_user`, e i
     * permessi che il moderatore riceve (§3 del piano — approvazioni,
     * moderazione, segnalazioni) sono per definizione permessi globali, mai
     * legati a un locale specifico.
     */
    protected function isGlobalStaff(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value, UserRole::Moderator->value]);
    }

    /**
     * Vero se `$permission` arriva a `$user` da un ruolo di staff globale
     * (moderatore, admin, super admin), non da `venue_owner` o
     * `venue_editor`.
     *
     * È la sola domanda che apre la scorciatoia «qualunque locale»: spatie
     * unisce i permessi di tutti i ruoli, e un moderatore che ha anche
     * `venue_owner` (per un locale suo, o per un ruolo dato per sbaglio)
     * riceveva `venues.update`, `events.delete` e il resto su tutti i locali
     * della piattaforma. Così i permessi di referente valgono solo dove la
     * persona è davvero referente, come per chiunque altro.
     *
     * Il permesso e i suoi ruoli si leggono dalla cache di spatie, la stessa
     * di `$user->can()`: nessuna query in più per riga.
     */
    protected function staffCan(User $user, string $permission): bool
    {
        if (! $this->isGlobalStaff($user)) {
            return false;
        }

        try {
            $model = Permission::findByName($permission, 'web');
        } catch (PermissionDoesNotExist) {
            return false;
        }

        $staff = [UserRole::Admin->value, UserRole::SuperAdmin->value, UserRole::Moderator->value];
        $roles = $model->roles
            ->map(static fn (Role $role): string => (string) $role->name)
            ->filter(static fn (string $name): bool => in_array($name, $staff, true))
            ->values()
            ->all();

        return $roles !== [] && $user->hasAnyRole($roles);
    }

    protected function isMemberOfVenue(User $user, int $venueId): bool
    {
        return $user->isMemberOfVenue($venueId);
    }

    protected function isOwnerOfVenue(User $user, int $venueId): bool
    {
        return $user->isOwnerOfVenue($venueId);
    }

    /**
     * Vero se l'utente ha il permesso indicato E, quando il permesso non gli
     * arriva da un ruolo di staff globale (`staffCan()`), appartiene proprio
     * a quel locale (come owner se `$requireOwner` è vero, altrimenti anche
     * come editor).
     *
     * Questo è l'unico punto che decide se un owner/editor del locale A può
     * toccare qualcosa del locale B: la risposta è sempre no, a prescindere
     * da come l'ID del locale B è arrivato alla richiesta.
     */
    protected function canActOnVenue(User $user, int $venueId, string $permission, bool $requireOwner = false): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        if ($this->staffCan($user, $permission)) {
            return true;
        }

        return $requireOwner
            ? $this->isOwnerOfVenue($user, $venueId)
            : $this->isMemberOfVenue($user, $venueId);
    }
}
