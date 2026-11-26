<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Notifications\EmailAddressChanged;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

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
 *   `password_impostata_da_staff`), con chi l'ha fatto;
 * - un'email cambiata avvisa il vecchio indirizzo (`EmailAddressChanged`):
 *   è il primo passo di chi si prende un account, e il titolare deve
 *   saperlo finché può reagire;
 * - chi cambia **la propria** email la riconferma, come alla registrazione:
 *   `email_verified_at` si azzera e al nuovo indirizzo parte il collegamento
 *   di verifica di sempre (`User::sendEmailVerificationNotification`). Il
 *   telefono sbloccato di qualcun altro non basta più a spostare l'account
 *   su una casella mai dimostrata. L'email di un'altra persona (solo dal
 *   pannello) la conferma chi la cambia, con il campo «Email verificata».
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
     * Vero se è un cambio della propria email: da riconfermare.
     *
     * @param  list<string>  $changes
     */
    public static function ownEmailChange(User $user, array $changes, ?User $staff): bool
    {
        return in_array('email_cambiata_da_staff', $changes, true) && $staff instanceof User && $staff->is($user);
    }

    /**
     * Dopo il salvataggio: revoca token e dispositivi, scrive il registro e,
     * se l'email è cambiata, avvisa il vecchio indirizzo e (per la propria)
     * chiede di confermare il nuovo. Senza cambi di credenziali non fa niente.
     *
     * @param  list<string>  $changes
     * @param  string|null  $previousEmail  l'indirizzo prima del salvataggio
     */
    public static function afterCredentialChanges(User $user, array $changes, ?User $staff, ?string $previousEmail = null): void
    {
        if ($changes === []) {
            return;
        }

        $user->tokens()->delete();
        $user->revokeDevices();
        foreach ($changes as $event) {
            SecurityLog::scrivi($event, $user, causa: $staff);
        }

        if (! in_array('email_cambiata_da_staff', $changes, true)) {
            return;
        }

        $own = self::ownEmailChange($user, $changes, $staff);
        /* Un server di posta che non risponde non annulla il cambio già salvato:
           l'errore va nei log, il registro di sicurezza ha già la riga. */
        try {
            if (filled($previousEmail)) {
                Notification::route('mail', (string) $previousEmail)->notify(new EmailAddressChanged((string) $user->email, byStaff: ! $own));
            }
            if ($own && ! $user->hasVerifiedEmail()) {
                $user->sendEmailVerificationNotification();
            }
        } catch (Throwable $exception) {
            report($exception);
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
        $previousEmail = (string) $user->email;
        $changes = DB::transaction(function () use ($staff, $user, $data): array {
            $changes = self::credentialChanges($user, $data);
            if (! filled($data['password'] ?? null)) {
                unset($data['password']);
            }
            $user->fill($data);
            if (self::ownEmailChange($user, $changes, $staff)) {
                $user->forceFill(['email_verified_at' => null]);
            }
            $user->save();

            return $changes;
        });
        // Fuori dalla transazione: le email partono solo per un cambio salvato davvero.
        self::afterCredentialChanges($user, $changes, $staff, $previousEmail);

        return $changes;
    }
}
