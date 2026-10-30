<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * La prima prova di possesso della casella **azzera** le credenziali nate
 * prima di essa.
 *
 * Registrarsi non prova niente: chiunque può creare l'account con l'indirizzo
 * di un altro, scegliere la password e tenersi il token dell'API o la
 * sessione. Se il vero titolare poi entra con il collegamento di accesso,
 * senza questo passaggio si ritroverebbe in un account in cui l'intruso è
 * ancora dentro e rientra quando vuole.
 *
 * Vale finché l'indirizzo non è verificato, oppure finché chi si è registrato
 * non ha dimostrato di essere anche il titolare della casella
 * (`email_claim_pending_at`): la conferma aperta dal telefono, senza la
 * sessione di chi si è iscritto, verifica l'indirizzo ma non dice chi ha
 * scelto la password. Chi ha già dimostrato la casella non perde niente: né
 * password né token degli altri dispositivi.
 */
final class ClaimMailbox
{
    public function __invoke(User $user): void
    {
        $verificato = $user->hasVerifiedEmail();

        if ($verificato && $user->email_claim_pending_at === null) {
            return;
        }

        $this->azzera($user, [
            'email_claim_pending_at' => null,
            'email_verified_at' => $user->email_verified_at ?? CarbonImmutable::now(),
        ]);

        if (! $verificato) {
            event(new Verified($user));
        }

        SecurityLog::scrivi('casella_rivendicata', $user);
    }

    /**
     * Un accesso dato da altri (l'invito in un locale, l'approvazione di una
     * richiesta) a un account la cui casella non è ancora dimostrata: chi ha
     * scelto la password può essere chiunque abbia registrato quell'indirizzo,
     * e con il pannello entrerebbe proprio lui. Le credenziali si azzerano
     * come alla prima prova della casella, ma la casella resta da dimostrare:
     * l'invito non prova niente, lo fa la password scelta dal collegamento
     * che il messaggio porta. Dice se ha azzerato, cioè se quel messaggio
     * deve portare il collegamento per scegliere la password.
     *
     * Solo per email mai confermate: chi ha confermato la casella (anche dal
     * browser, con il sospeso ancora aperto) è quasi sempre il titolare, e
     * azzerarlo darebbe a qualunque referente il potere di scollegare e
     * togliere la password a un utente vero con un invito.
     */
    public function revocaPerAccessoConcesso(User $user, ?User $causa = null): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $this->azzera($user, []);
        SecurityLog::scrivi('credenziali_azzerate_per_accesso', $user, causa: $causa);

        return true;
    }

    /**
     * @param  array<string, mixed>  $attributi
     */
    private function azzera(User $user, array $attributi): void
    {
        $user->tokens()->delete();
        MobileAuthChallenge::query()->where('user_id', $user->getKey())->delete();

        /* Le sessioni del sito: con il driver `database` si cancellano le
           righe; con gli altri basta la password nuova, perché
           `AuthenticateWebSession` chiude le sessioni con l'impronta vecchia. */
        if (config('session.driver') === 'database') {
            $connessione = config('session.connection');

            DB::connection(is_string($connessione) ? $connessione : null)
                ->table(config()->string('session.table'))
                ->where('user_id', $user->getKey())
                ->delete();
        }

        $user->forceFill([
            /* Nessuno conosce questa password: chi la vuole se ne sceglie una
               con «password dimenticata». Cambia anche l'impronta, quindi i
               collegamenti già spediti smettono di valere. */
            'password' => Hash::make(Str::random(64)),
            'remember_token' => Str::random(60),
            ...$attributi,
        ])->save();
    }
}
