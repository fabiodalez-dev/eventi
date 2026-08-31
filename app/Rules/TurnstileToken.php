<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Turnstile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Verifica lato server del gettone prodotto dal riquadro Turnstile (§14.7).
 *
 * Il gettone che arriva col modulo non prova niente da solo: la prova è la
 * risposta di Cloudflare, che va chiesta **una volta sola** — il servizio
 * rifiuta il secondo controllo dello stesso gettone, ed è così che impedisce
 * di riusarne uno catturato.
 *
 * Tre scelte che non si vedono dal codice ma contano:
 *
 * - l'indirizzo IP di chi invia viene passato: senza, un gettone rubato vale
 *   da qualunque rete;
 * - il timeout è breve. Se Cloudflare non risponde, il modulo pubblico di un
 *   sito di eventi deve tornare disponibile, non restare fermo finché un
 *   servizio esterno ci ripensa;
 * - un guasto del servizio **non blocca l'invio**. Restano in piedi il campo
 *   esca e il limite di frequenza, che sono nostri. Il contrario — chiudere
 *   il modulo perché un terzo è irraggiungibile — trasformerebbe un disservizio
 *   altrui in un disservizio nostro. L'episodio finisce nel log.
 */
final class TurnstileToken implements ValidationRule
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('validation.custom.turnstile.missing'));

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(config()->integer('services.turnstile.timeout'))
                ->post(self::VERIFY_URL, [
                    'secret' => Turnstile::secretKey(),
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Turnstile irraggiungibile: invio accettato senza verifica.', [
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($response->serverError()) {
            Log::warning('Turnstile ha risposto con un errore: invio accettato senza verifica.', [
                'status' => $response->status(),
            ]);

            return;
        }

        if ($response->json('success') === true) {
            return;
        }

        $fail(__('validation.custom.turnstile.failed'));
    }
}
