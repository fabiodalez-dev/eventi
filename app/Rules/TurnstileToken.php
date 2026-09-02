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
     * @param  string|null  $azione  il `data-action` che il widget deve aver dichiarato
     */
    public function __construct(private readonly ?string $azione = null) {}

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

        if ($response->json('success') !== true) {
            $fail(__('validation.custom.turnstile.failed'));

            return;
        }

        /*
         * **Non basta che il gettone sia valido: deve essere stato risolto da
         * casa nostra.**
         *
         * La site key e' pubblica per costruzione — sta nell'HTML di ogni
         * pagina. Chiunque puo' copiare quel markup su un dominio proprio,
         * far risolvere il widget (da persone vere, o da un servizio che lo fa
         * a pagamento) e spedire i gettoni a questo endpoint: sono gettoni
         * autentici, `success` risponde `true`, e senza questo controllo
         * passano. Cloudflare dice da quale host e' stato risolto, ed e'
         * l'unico modo per accorgersene.
         *
         * L'elenco vuoto significa «non lo so», e allora non si giudica: e' il
         * caso di un'installazione senza `APP_URL` sensato, dove rifiutare
         * tutto sarebbe peggio del rischio.
         */
        $ammessi = Turnstile::hostnames();
        $host = $response->json('hostname');

        if ($ammessi !== [] && is_string($host) && ! in_array($host, $ammessi, strict: true)) {
            Log::warning('Turnstile: gettone risolto su un dominio che non e nostro.', [
                'hostname' => $host,
                'ammessi' => $ammessi,
            ]);

            $fail(__('validation.custom.turnstile.failed'));

            return;
        }

        /*
         * E deve venire dal modulo giusto. Senza, un gettone ottenuto sul
         * modulo meno sorvegliato vale per tutti gli altri: se ne risolve uno
         * dove costa meno e lo si spende dove serve.
         *
         * Il confronto avviene solo se il widget ha dichiarato un'azione:
         * `data-action` e' facoltativo, e un modulo che non lo mette non deve
         * smettere di funzionare.
         */
        $azioneAttesa = $this->azione;
        $azione = $response->json('action');

        if ($azioneAttesa !== null && is_string($azione) && $azione !== $azioneAttesa) {
            Log::warning('Turnstile: gettone risolto per un altro modulo.', [
                'atteso' => $azioneAttesa,
                'ricevuto' => $azione,
            ]);

            $fail(__('validation.custom.turnstile.failed'));
        }
    }
}
