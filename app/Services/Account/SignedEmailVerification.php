<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * La verifica dell'email fatta a partire dal **collegamento intero**.
 *
 * Serve all'API (§15.8), dove l'indirizzo arriva incollato dall'app e non
 * attraverso una richiesta del browser: `URL::hasValidSignature()` vuole una
 * richiesta, e questa classe è il solo posto in cui se ne fabbrica una da una
 * stringa. Il sito passa invece dalla rotta firmata e dal middleware `signed`,
 * che fa lo stesso controllo sulla richiesta vera.
 *
 * Il `hash` da solo non prova nulla — è `sha1(email)`, calcolabile da chiunque
 * conosca l'indirizzo: senza firma valida questa classe non verifica niente.
 */
final class SignedEmailVerification
{
    /**
     * `$requester` è chi ha aperto il collegamento, se è collegato: quando è
     * lo stesso account, chi si è registrato ha dimostrato anche la casella.
     */
    public function verify(string $url, ?User $requester = null): ?User
    {
        $request = Request::create($url);

        if (! URL::hasValidSignature($request)) {
            return null;
        }

        /*
         * `Request::create()` non passa dal router, quindi i segnaposti
         * dell'indirizzo non sono ancora niente: si chiede alla tabella delle
         * rotte di riconoscerlo. Leggere gli ultimi due segmenti del percorso
         * sarebbe più corto e si romperebbe il giorno in cui l'indirizzo
         * cambia forma.
         */
        try {
            $route = app(Router::class)->getRoutes()->match($request);
        } catch (HttpException) {
            return null;
        }

        $parameters = $route->parameters();
        $id = $parameters['id'] ?? null;
        $hash = $parameters['hash'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $user = User::query()->find((int) $id);

        if (! $user instanceof User || ! is_string($hash) || ! hash_equals(sha1((string) $user->email), $hash)) {
            return null;
        }

        return $this->markVerified($user, $requester?->is($user) ?? false);
    }

    /**
     * Segnare due volte non è un errore: chi apre il collegamento dal telefono
     * dopo averlo già aperto dal computer non deve vedere un errore per aver
     * fatto la cosa giusta due volte. La data però non si riscrive, perché
     * `email_verified_at` racconta **quando** è avvenuta la verifica.
     *
     * Aperto dalla sessione (o dal token) di chi si è registrato, il
     * collegamento prova che chi ha scelto la password possiede la casella, e
     * le credenziali smettono di essere in sospeso. Aperto altrove — il
     * telefono senza sessione, il browser di un altro account — verifica
     * l'indirizzo e basta: chi ha scelto la password resta da dimostrare, e
     * il primo accesso con il collegamento azzererà le credenziali
     * (`ClaimMailbox`). Azzerarle già qui butterebbe fuori dall'app chi si è
     * iscritto dal telefono e conferma dal browser, che è il caso normale.
     */
    public function markVerified(User $user, bool $byOwner = false): User
    {
        if ($byOwner && $user->email_claim_pending_at !== null) {
            $user->forceFill(['email_claim_pending_at' => null])->save();
        }

        if ($user->hasVerifiedEmail()) {
            return $user;
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        return $user;
    }
}
