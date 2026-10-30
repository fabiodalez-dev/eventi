<?php

declare(strict_types=1);

namespace App\Support;

/**
 * L'indirizzo su cui contare un tetto «per IP».
 *
 * Con IPv4 l'indirizzo intero; con IPv6 il prefisso /64, perché è quello che
 * un singolo host riceve di norma: dentro il /64 si cambia indirizzo a ogni
 * richiesta, a costo zero, e un tetto sull'indirizzo completo non frena
 * nessuno. Un IPv4 scritto in forma IPv6 (::ffff:a.b.c.d) torna IPv4, così le
 * due forme dello stesso indirizzo finiscono nello stesso secchio.
 */
final class IpBucket
{
    public static function key(?string $ip): string
    {
        $packed = $ip === null || $ip === '' ? false : @inet_pton($ip);
        if ($packed === false) {
            return $ip === null || $ip === '' ? 'sconosciuto' : $ip;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }
        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
