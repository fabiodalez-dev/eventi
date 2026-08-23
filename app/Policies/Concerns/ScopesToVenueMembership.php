<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\User;

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

    protected function isMemberOfVenue(User $user, int $venueId): bool
    {
        return $user->venues()->whereKey($venueId)->exists();
    }

    protected function isOwnerOfVenue(User $user, int $venueId): bool
    {
        return $user->ownedVenues()->whereKey($venueId)->exists();
    }

    /**
     * Vero se l'utente ha il permesso indicato E, quando non fa parte dello
     * staff globale, appartiene proprio a quel locale (come owner se
     * `$requireOwner` è vero, altrimenti anche come editor).
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

        if ($this->isGlobalStaff($user)) {
            return true;
        }

        return $requireOwner
            ? $this->isOwnerOfVenue($user, $venueId)
            : $this->isMemberOfVenue($user, $venueId);
    }
}
