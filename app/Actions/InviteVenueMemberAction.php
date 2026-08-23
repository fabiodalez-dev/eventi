<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\VenueAccessGranted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dà a una persona le chiavi di un locale (§10.6).
 *
 * Serve a due gesti che sembrano diversi e sono lo stesso: la redazione che
 * accredita il referente di un locale appena approvato, e il referente che
 * invita un collaboratore. In entrambi i casi occorre — nell'ordine — un
 * account, un ruolo globale, una riga in `venue_user` e un messaggio che
 * spieghi come entrare. Scriverlo due volte significherebbe che una delle due
 * strade, prima o poi, dimentica un passaggio.
 *
 * **L'account si crea con una password casuale, non con una password nota.**
 * Chi riceve l'invito sceglie la propria dal collegamento nel messaggio: una
 * password predefinita, anche temporanea, resterebbe valida finché qualcuno
 * non la cambia.
 *
 * Il ruolo globale non viene mai declassato: se la persona è già
 * amministratore, l'invito aggiunge l'appartenenza al locale e non tocca
 * quello che era.
 */
final class InviteVenueMemberAction
{
    public function execute(Venue $venue, string $email, string $name, VenueRole $role): User
    {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($venue, $email, $name, $role): User {
            $user = User::query()->where('email', $email)->first();
            $isNew = ! $user instanceof User;

            if ($isNew) {
                $user = User::query()->create([
                    'name' => trim($name) !== '' ? trim($name) : $email,
                    'email' => $email,
                    'password' => Str::password(32),
                ]);
            }

            $this->ensureGlobalRole($user, $role);

            $venue->members()->syncWithoutDetaching([
                $user->getKey() => [
                    'role' => $role->value,
                    'invited_at' => now(),
                ],
            ]);

            $user->notify(new VenueAccessGranted($venue, $role, $isNew));

            return $user;
        });
    }

    /**
     * Il permesso di agire arriva dal ruolo globale (§3), l'indicazione di
     * *quale* locale dalla pivot: servono tutti e due, ed è questo il punto
     * che non li lascia mai separati.
     */
    private function ensureGlobalRole(User $user, VenueRole $role): void
    {
        $needed = $role === VenueRole::Owner ? UserRole::VenueOwner : UserRole::VenueEditor;

        if ($user->hasRole($needed->value)) {
            return;
        }

        $user->assignRole($needed->value);
    }
}
