<?php

declare(strict_types=1);

namespace App\Support\Media;

/**
 * I nomi delle varianti di §12.1 e la regola con cui si chiama la versione
 * AVIF di ciascuna.
 *
 * Sta qui, e non dentro il modello, perché lo stesso elenco serve a tre parti
 * che non si conoscono: chi **dichiara** le conversioni (`Event`, `Venue`),
 * chi le **legge** per costruire un `<picture>` (`ImageSet`) e chi le
 * **espone** nell'API (`PosterResource`). Una regola di denominazione scritta
 * tre volte diverge alla prima variante aggiunta.
 */
final class Variants
{
    /**
     * Nome della variante → larghezza in pixel.
     *
     * @return array<string, int>
     */
    public static function widths(): array
    {
        /** @var array<string, int> $widths */
        $widths = config()->array('media.variants');

        return $widths;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::widths());
    }

    /**
     * Il nome della conversione AVIF che accompagna una variante.
     */
    public static function avif(string $variant): string
    {
        return $variant.'-avif';
    }
}
