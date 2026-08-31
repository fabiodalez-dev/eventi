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
    public static function rules(): array
    {
        if (! self::enabled()) {
            return [];
        }

        return [self::FIELD => ['required', 'string', new TurnstileToken]];
    }
}
