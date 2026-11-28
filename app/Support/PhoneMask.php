<?php

declare(strict_types=1);

namespace App\Support;

/**
 * I numeri di telefono dentro un testo libero, coperti.
 *
 * Serve ai messaggi d'errore delle consegne (WhatsApp, SMS): il servizio di
 * invio li scrive così come gli arrivano, e spesso contengono il numero del
 * destinatario. Allo staff basta sapere che l'invio è fallito e perché; il
 * numero intero no. Restano visibili solo le ultime due cifre, che bastano a
 * distinguere due consegne della stessa persona.
 *
 * È un numero di telefono una sequenza di almeno 8 cifre, con il `+`
 * davanti o no, e con spazi, punti, trattini, barre o parentesi in mezzo;
 * due numeri di fila separati da uno spazio diventano una copertura sola.
 * Le date scritte come `2026-11-26` non si toccano.
 */
final class PhoneMask
{
    /** Le cifre che restano visibili alla fine. */
    public const VISIBLE = 2;

    private const PATTERN = '/(?<![\w+])(?!\d{4}-\d{2}-\d{2}(?!\d))\+?\(?\d[\d\s().\/-]{6,}\d(?!\w)/u';

    public static function mask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return preg_replace_callback(self::PATTERN, static function (array $match): string {
            $digits = preg_replace('/\D/', '', $match[0]) ?? '';
            if (strlen($digits) < 8) {
                return $match[0];
            }

            return '•••'.substr($digits, -self::VISIBLE);
        }, $text) ?? $text;
    }
}
