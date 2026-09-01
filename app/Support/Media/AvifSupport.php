<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Enums\ImageType;
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
 * **Il controllo prova a scrivere davvero un AVIF**, e non si limita a chiedere
 * a ImageMagick se conosce il formato. `Imagick::queryFormats('AVIF')` elenca i
 * formati NOTI, che non è la stessa cosa dei formati scrivibili: su una
 * macchina senza libheif quella chiamata risponde di sì e la scrittura produce
 * comunque un JPEG. È esattamente il caso in cui siamo caduti — la domanda
 * sbagliata dava la risposta rassicurante.
 *
 * Costa la codifica di un'immagine di un pixel, una volta per processo.
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

        return self::$supported = self::canReallyEncode();
    }

    /**
     * Scrive un pixel in AVIF e guarda cosa ne è uscito.
     *
     * ImageMagick, richiesto un formato che non sa codificare, non solleva:
     * ricade su un altro formato e restituisce quello. Quindi non basta che la
     * chiamata vada a buon fine — bisogna leggere i primi byte del risultato e
     * verificare che siano davvero quelli di un AVIF.
     */
    private static function canReallyEncode(): bool
    {
        try {
            $imagick = new Imagick;
            $imagick->newImage(1, 1, 'white');
            $imagick->setImageFormat('avif');

            $blob = $imagick->getImageBlob();

            $imagick->clear();
        } catch (\Throwable) {
            /* Un'eccezione qui è già una risposta: questa installazione non
               scrive AVIF. */
            return false;
        }

        return ImageType::fromHeader($blob) === ImageType::Avif;
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
