<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * La scheda di un account modificata dalla redazione (R-US-05), con la regola
 * che era in `EditUser`: email e password cambiate dallo staff sono il modo
 * di prendersi un account altrui, quindi
 *
 * - si annotano quando ancora si vede il valore di prima
 *   (`credentialChanges()`);
 * - dopo il salvataggio cadono tutti i token dell'app e tutti i dispositivi
 *   registrati per le notifiche, come dopo una reimpostazione della password
 *   (`PasswordReset` in `AppServiceProvider`);
 * - ogni cambio va nel registro di sicurezza (`email_cambiata_da_staff`,
 *   `password_impostata_da_staff`), con chi l'ha fatto.
 *
 * La chiamano la pagina «Modifica utente» del pannello (in due tempi, perché
 * Filament salva in mezzo) e l'app della redazione (`update()`).
 */
final class UpdateUserByStaff
{
    /**
     * I cambi di credenziali che `$data` porterebbe a `$user`.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function credentialChanges(User $user, array $data): array
    {
        $changes = [];
        if (array_key_exists('email', $data) && mb_strtolower(trim((string) $data['email'])) !== mb_strtolower((string) $user->getAttribute('email'))) {
            $changes[] = 'email_cambiata_da_staff';
        }
        if (filled($data['password'] ?? null)) {
            $changes[] = 'password_impostata_da_staff';
        }

        return $changes;
    }

    /**
     * Dopo il salvataggio: revoca token e dispositivi e scrive il registro.
     * Senza cambi di credenziali non fa niente.
     *
     * @param  list<string>  $changes
     */
    public static function afterCredentialChanges(User $user, array $changes, ?User $staff): void
    {
        if ($changes === []) {
            return;
        }

        $user->tokens()->delete();
        $user->revokeDevices();
        foreach ($changes as $event) {
            SecurityLog::scrivi($event, $user, causa: $staff);
        }
    }

    /**
     * Salva i campi della scheda e applica la regola delle credenziali. I
     * campi passano dal `$fillable` del modello, come nel pannello (che
     * salva con `update()`).
     *
     * @param  array<string, mixed>  $data
     * @return list<string> i cambi di credenziali avvenuti
     */
    public function update(?User $staff, User $user, array $data): array
    {
        return DB::transaction(function () use ($staff, $user, $data): array {
            $changes = self::credentialChanges($user, $data);
            if (! filled($data['password'] ?? null)) {
                unset($data['password']);
            }
            $user->update($data);
            self::afterCredentialChanges($user, $changes, $staff);

            return $changes;
        });
    }
}
