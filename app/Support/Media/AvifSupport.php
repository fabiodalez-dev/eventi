<?php

declare(strict_types=1);

namespace App\Support\Media;

use Imagick;

/**
 * Se questa installazione sappia davvero scrivere AVIF.
 *
 * **Serve perché il fallimento è silenzioso.** ImageMagick, richiesto un AVIF
 * senza avere il delegato corrispondente (libheif), non solleva niente: scrive
 * un JPEG e gli dà il nome che gli è stato chiesto. Il file `.avif` esiste,
 * pesa il giusto, e non è un AVIF.
 *
 * A valle, il `<picture>` lo annuncia come `type="image/avif"`: un browser che
 * dichiara di accettare AVIF sceglie proprio quella fonte e riceve un JPEG con
 * il tipo sbagliato. Alcuni lo mostrano lo stesso, altri no — e la variante
 * WebP, che avrebbe funzionato, non viene nemmeno considerata.
 *
 * Meglio non generarla affatto: senza la variante, il `<picture>` non emette
 * quella fonte e ogni browser prende il WebP. Si perde il 20-40% di risparmio
 * dell'AVIF su quella installazione, e non si perde l'immagine.
 *
 * Il controllo è memorizzato per la durata della richiesta: interroga i formati
 * di ImageMagick, e ripeterlo per ogni variante di ogni immagine costerebbe
 * senza cambiare risposta.
 */
final class AvifSupport
{
    private static ?bool $supported = null;

    public static function available(): bool
    {
        if (self::$supported !== null) {
            return self::$supported;
        }

        if (! class_exists(Imagick::class)) {
            return self::$supported = false;
        }

        /* `queryFormats` elenca i formati che QUESTA build sa trattare: è la
           sola risposta attendibile, perché il supporto dipende dai delegati
           compilati, non dalla versione. */
        return self::$supported = Imagick::queryFormats('AVIF') !== [];
    }

    /**
     * Dimentica la risposta. Serve ai test, che devono poter verificare
     * entrambi i rami senza ricompilare ImageMagick.
     */
    public static function forget(): void
    {
        self::$supported = null;
    }

    /**
     * Impone una risposta. Solo per i test: in esercizio la verità la dice
     * `queryFormats`.
     */
    public static function fake(bool $supported): void
    {
        self::$supported = $supported;
    }
}
