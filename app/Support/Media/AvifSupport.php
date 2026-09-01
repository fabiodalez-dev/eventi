<?php

declare(strict_types=1);

namespace App\Support\Media;

use Imagick;

/**
 * Se questa installazione sappia scrivere AVIF.
 *
 * **Perché la domanda si fa così e non provando a codificare.** La risposta
 * onesta sarebbe scrivere un'immagine e guardare cosa ne esce — chiedere a
 * ImageMagick quali formati conosce non è la stessa cosa che chiedergli quali
 * sa scrivere, e su una macchina senza libheif `queryFormats('AVIF')` risponde
 * di sì mentre la codifica produce un JPEG.
 *
 * Ma quella prova non si può fare qui. AV1 nasce come codifica video e lavora
 * per blocchi: su certe dimensioni il codificatore AOM non fallisce con garbo,
 * va in `assert` e **abbatte il processo PHP**. Un controllo che sta nel
 * percorso di ogni richiesta — questo lo è, perché le conversioni si
 * dichiarano quando il modello si registra — non può permettersi di poter
 * uccidere l'applicazione: il difetto che evita è una variante sbagliata, il
 * prezzo sarebbe il sito intero a 503. È successo, in produzione, per una
 * prova su un'immagine di un pixel.
 *
 * Quindi qui si fa la domanda economica, e la verità si verifica **a valle**:
 * `RejectFakeAvifConversion` guarda il file appena prodotto e, se AVIF non è,
 * lo elimina. Il controllo costoso avviene una volta per immagine, in coda,
 * dove un errore non travolge nessuno.
 *
 * `MEDIA_AVIF=false` spegne tutto, per chi sa che la propria macchina mente.
 */
final class AvifSupport
{
    private static ?bool $supported = null;

    public static function available(): bool
    {
        if (self::$supported !== null) {
            return self::$supported;
        }

        /* Un interruttore esplicito vince sempre: chi ha una macchina che
           dichiara il falso deve poterlo dire una volta invece di combattere
           con le varianti a ogni caricamento. */
        $configured = config('media.avif');

        if (is_bool($configured)) {
            return self::$supported = $configured;
        }

        if (! class_exists(Imagick::class)) {
            return self::$supported = false;
        }

        return self::$supported = Imagick::queryFormats('AVIF') !== [];
    }

    /** Dimentica la risposta. Serve ai test. */
    public static function forget(): void
    {
        self::$supported = null;
    }

    /** Impone una risposta. Solo per i test. */
    public static function fake(bool $supported): void
    {
        self::$supported = $supported;
    }
}
