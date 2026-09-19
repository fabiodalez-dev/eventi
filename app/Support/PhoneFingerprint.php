<?php

declare(strict_types=1);

namespace App\Support;

/**
 * L'impronta di un numero WhatsApp: una sola definizione, usata dalla verifica
 * e dal comando che la ricalcola quando si ruota la chiave. Due copie della
 * stessa formula prima o poi divergono, e allora nessuna impronta ricalcolata
 * torna più uguale a quella che la verifica cerca.
 */
final class PhoneFingerprint
{
    public static function of(#[\SensitiveParameter] string $phone, #[\SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', $phone, $key);
    }
}
