<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Models\ImportSource;
use Illuminate\Support\Str;

/**
 * Il solo punto in cui si scrive e si riconosce `events.source_ref`.
 *
 * §14.2 fissa l'idempotenza «su `source_ref`» e il valore naturale è l'`UID`
 * della voce ICS (più il `RECURRENCE-ID`, quando la voce è l'eccezione di una
 * serie). Il valore scritto in colonna porta però **davanti l'identificativo
 * della sorgente**, ed è una scelta con una ragione precisa: senza, non
 * esisterebbe alcun modo di chiedere al database *quali eventi vengono da
 * questo calendario*.
 *
 * Serve a due cose che senza sarebbero impossibili:
 *
 * 1. riconoscere le voci **sparite** dal feed (§14.2 le vuole marcate, non
 *    cancellate) — il confronto è fra l'insieme visto adesso e l'insieme
 *    scritto in passato **da quella sorgente**;
 * 2. non far collidere due calendari della stessa città che dichiarassero lo
 *    stesso `UID`. L'RFC 5545 lo vuole unico al mondo, ma un `UID` è una
 *    stringa scritta da chi esporta, e "1" è un valore che si incontra davvero.
 *
 * `events.source_ref` è `VARCHAR(255)`: un `UID` più lungo del resto
 * disponibile viene sostituito dalla propria impronta, che resta stabile fra
 * un'esecuzione e l'altra — ed è la stabilità, non la leggibilità, che
 * l'idempotenza richiede.
 */
final class SourceRef
{
    /**
     * Il limite della colonna `events.source_ref`.
     */
    public const MAX_LENGTH = 255;

    public static function prefix(ImportSource $source): string
    {
        return $source->getKey().':';
    }

    public static function make(ImportSource $source, string $key): string
    {
        $prefix = self::prefix($source);
        $ref = $prefix.$key;

        if (strlen($ref) <= self::MAX_LENGTH) {
            return $ref;
        }

        return $prefix.'sha1:'.sha1($key);
    }

    /**
     * Il pattern per una `LIKE`, con i caratteri jolly di SQL neutralizzati:
     * l'identificativo è un numero, ma il carattere di escape va comunque
     * dichiarato perché la query resti corretta se un giorno non lo fosse più.
     */
    public static function likePattern(ImportSource $source): string
    {
        return Str::of(self::prefix($source))
            ->replace('\\', '\\\\')
            ->replace('%', '\\%')
            ->replace('_', '\\_')
            ->append('%')
            ->value();
    }
}
