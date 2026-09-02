<?php

declare(strict_types=1);

namespace App\Support;

use App\Rules\TurnstileToken;

/**
 * Cloudflare Turnstile sui moduli pubblici (§14.7).
 *
 * È la terza barriera, dopo il campo esca (`Honeypot`) e il limite di
 * frequenza per indirizzo IP: il primo ferma i robot generici, il secondo chi
 * insiste, questa chi scrive un robot apposta per questo sito.
 *
 * **Si accende solo quando entrambe le chiavi esistono.** Senza, il modulo non
 * disegna nulla e la validazione non chiede nulla: sviluppo e test non devono
 * dipendere da un servizio esterno raggiungibile, e un ambiente configurato a
 * metà — chiave pubblica sì, segreta no — respingerebbe ogni invio con un
 * errore che non dice niente a chi lo riceve.
 *
 * Il nome del campo lo decide Cloudflare, non noi: il riquadro scrive da sé un
 * `<input name="cf-turnstile-response">` dentro il modulo che lo ospita.
 */
final class Turnstile
{
    public const FIELD = 'cf-turnstile-response';

    public static function enabled(): bool
    {
        return self::siteKey() !== '' && self::secretKey() !== '';
    }

    public static function siteKey(): string
    {
        return trim((string) config('services.turnstile.site_key'));
    }

    public static function secretKey(): string
    {
        return trim((string) config('services.turnstile.secret_key'));
    }

    /**
     * Le regole da fondere in quelle di un Form Request. Vuote quando le
     * chiavi mancano: è ciò che rende la protezione facoltativa senza
     * duplicare un `if` in ogni modulo.
     *
     * @return array<string, array<int, mixed>>
     */
    /**
     * I domini da cui accettiamo un gettone risolto.
     *
     * **Il valore predefinito e' l'host di `APP_URL`, e non e' una scorciatoia
     * pigra.** Questo dominio e' provvisorio: elencarlo a mano in una
     * configurazione significherebbe che il giorno del trasloco i moduli
     * pubblici cominciano a rifiutare tutti gli invii, con un messaggio che
     * parla di verifica fallita e non dice una parola sul dominio. Ricavarlo
     * da `APP_URL` lo fa seguire da solo.
     *
     * @return list<string>
     */
    public static function hostnames(): array
    {
        /** @var list<string> $dichiarati */
        $dichiarati = config()->array('services.turnstile.hostnames');

        if ($dichiarati !== []) {
            return $dichiarati;
        }

        $host = parse_url(config()->string('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? [$host] : [];
    }

    /**
     * Le regole di validazione del gettone, o niente se Turnstile non è
     * configurato.
     *
     * @param  string|null  $azione  il `data-action` dichiarato dal widget di
     *                               quel modulo: il server ricontrolla che il
     *                               gettone venga da lì e non da un altro
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?string $azione = null): array
    {
        if (! self::enabled()) {
            return [];
        }

        return [self::FIELD => ['required', 'string', new TurnstileToken($azione)]];
    }
}
