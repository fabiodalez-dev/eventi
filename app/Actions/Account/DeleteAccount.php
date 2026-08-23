<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\NotificationStatus;
use App\Models\ScheduledNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cancellazione dell'account, self-service e con effetto immediato (§15.2, §15.9).
 *
 * «Immediato» ha un significato preciso e verificabile: salvataggi, follow,
 * dispositivi, archivio delle notifiche e **token di accesso** spariscono nella
 * stessa transazione, e gli invii ancora in attesa passano a `cancelled` —
 * non si limitano a restare senza destinatario. Un promemoria che partisse il
 * giorno dopo verso l'indirizzo di chi ha chiesto di sparire sarebbe la sola
 * prova che serve per dire che la cancellazione non funziona.
 *
 * L'account non viene rimosso ma **anonimizzato e cestinato**: le pratiche dei
 * locali e le segnalazioni fatte in passato hanno chiavi `SET NULL` (§4 di
 * `SCHEMA.md`) e sopravvivono da sole, ma la riga deve restare perché la
 * cronologia editoriale conservi un riferimento che non è più una persona.
 * L'indirizzo diventa un `.invalid`: nessun messaggio potrà mai partire.
 */
final class DeleteAccount
{
    public function __invoke(User $user): void
    {
        DB::transaction(function () use ($user): void {
            ScheduledNotification::query()
                ->where('user_id', $user->getKey())
                ->where('status', NotificationStatus::Pending->value)
                ->update(['status' => NotificationStatus::Cancelled->value]);

            $user->savedEvents()->delete();
            $user->follows()->delete();
            $user->devices()->delete();
            $user->notifications()->delete();
            $user->tokens()->delete();

            $user->forceFill([
                'name' => null,
                'email' => $this->anonymousEmail($user),
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'notification_preferences' => null,
                'daily_digest_time' => null,
                'quiet_hours' => null,
                'marketing_opt_in_at' => null,
                'last_active_at' => null,
                'email_verified_at' => null,
            ])->save();

            $user->delete();
        });
    }

    /**
     * `.invalid` è riservato dalla RFC 2606: un indirizzo di quel dominio non
     * è consegnabile per definizione. L'identificativo lo rende unico, che è
     * ciò che l'indice `users.email` pretende anche dopo la cancellazione.
     */
    private function anonymousEmail(User $user): string
    {
        return sprintf(
            'utente-%d@%s',
            (int) $user->getKey(),
            config()->string('account.anonymized_email_domain'),
        );
    }
}
