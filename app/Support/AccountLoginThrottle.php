<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Il tetto per account sull'accesso con password: sito, API e pannelli.
 *
 * Conta i tentativi subiti dall'account, da qualunque indirizzo arrivino: è
 * la grandezza che un pool di indirizzi non moltiplica (vedi
 * `AppServiceProvider::limitiPerConto()`). Si contano solo i fallimenti, ma
 * il tentativo si conta **prima** di verificare la password e si restituisce
 * se l'accesso riesce. Contarlo dopo, a risposta arrivata, lasciava passare
 * il controllo a tutte le richieste in volo durante il bcrypt e il timebox
 * (mezzo secondo): con cento richieste parallele da cento indirizzi le
 * password provate erano cento, non venti. `increment` è atomico sulla cache,
 * quindi ogni richiesta vede già quelle partite prima di lei.
 *
 * Ogni porta ha il suo secchio, come prima: i tentativi sbagliati sull'API
 * non chiudono l'accesso dal sito, né quello del pannello.
 */
final class AccountLoginThrottle
{
    private const DECADIMENTO = 900;

    /**
     * Il secchio va all'account e non alla stringa: per la collation di
     * `users.email` 'àdmin@…' e 'admin@…' sono lo stesso utente, e ogni
     * variante accentata avrebbe altrimenti i suoi tentativi. Quando
     * l'account non c'è resta la stringa.
     */
    public static function chiave(string $porta, string $email): string
    {
        $email = mb_strtolower(trim($email));
        $id = User::query()->where('email', $email)->value('id');

        return 'accesso-conto:'.$porta.':'.($id !== null ? 'id:'.$id : hash('sha256', $email));
    }

    /**
     * Conta il tentativo prima della verifica. Restituisce `false`, senza
     * contarlo, se il tetto è già pieno.
     */
    public static function prenota(string $chiave): bool
    {
        if (RateLimiter::increment($chiave, self::DECADIMENTO) > config()->integer('api.rate_limit.attempts_per_account')) {
            RateLimiter::decrement($chiave, self::DECADIMENTO);

            return false;
        }

        return true;
    }

    /** Come `prenota()`, ma a tetto pieno risponde 429 come il limitatore delle rotte. */
    public static function prenotaOppure429(string $chiave): void
    {
        if (! self::prenota($chiave)) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => self::attesa($chiave)]);
        }
    }

    /**
     * L'accesso è riuscito, o la password non è stata verificata: il
     * tentativo non conta. Se nel frattempo il secchio è scaduto non si
     * scende sotto zero, che regalerebbe tentativi alla finestra dopo.
     */
    public static function restituisci(string $chiave): void
    {
        if ((int) RateLimiter::attempts($chiave) > 0) {
            RateLimiter::decrement($chiave, self::DECADIMENTO);
        }
    }

    /** Secondi prima che il secchio si svuoti. */
    public static function attesa(string $chiave): int
    {
        return max(1, RateLimiter::availableIn($chiave));
    }
}
