<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Account\ClaimMailbox;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\VenueAccessGranted;
use App\Support\EditorContent;
use App\Support\SecurityLog;
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
 * quello che era. Lo stesso vale per il ruolo nel locale.
 */
final class InviteVenueMemberAction
{
    public function execute(Venue $venue, string $email, string $name, VenueRole $role): User
    {
        $email = mb_strtolower(trim($email));
        $name = EditorContent::clean('name', $name);

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

            /*
             * Chi è già del locale non viene mai declassato da un invito: un
             * referente che invita l'email di un altro referente lo
             * trasformerebbe in collaboratore, e da lì potrebbe toglierlo.
             * Il ruolo nel locale si alza (collaboratore → referente, quando
             * accredita la redazione), mai si abbassa: per abbassarlo c'è la
             * modifica della riga nel pannello di redazione. E chi è già
             * dentro con lo stesso ruolo non riceve un secondo messaggio.
             */
            $current = $isNew ? null : $venue->members()->whereKey($user->getKey())->lockForUpdate()->first()?->getRelationValue('pivot')?->getAttribute('role');

            if ($current === VenueRole::Owner->value || $current === $role->value) {
                return $user;
            }

            $this->ensureGlobalRole($user, $role);

            $venue->members()->syncWithoutDetaching([
                $user->getKey() => [
                    'role' => $role->value,
                    'invited_at' => now(),
                ],
            ]);

            $causa = auth()->user() instanceof User ? auth()->user() : null;
            SecurityLog::scrivi('accesso_locale_concesso', $user, [
                'venue_id' => $venue->getKey(),
                'ruolo' => $role->value,
            ], causa: $causa);

            /*
             * Un account già esistente ma con la casella non dimostrata può
             * averlo registrato chiunque, con una password sua: senza questo
             * passaggio l'invito gli aprirebbe il pannello. Le credenziali si
             * azzerano e il messaggio porta il collegamento per sceglierne
             * una, che arriva solo nella casella vera.
             */
            $needsPassword = $isNew || app(ClaimMailbox::class)->revocaPerAccessoConcesso($user, $causa);

            $user->notify(new VenueAccessGranted($venue, $role, $needsPassword));

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
