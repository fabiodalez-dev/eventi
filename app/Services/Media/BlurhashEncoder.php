<?php

declare(strict_types=1);

namespace App\Services\Media;

use kornrunner\Blurhash\Blurhash;
use Spatie\Image\Image;
use Throwable;

/**
 * Il penultimo anello della pipeline di §12.1: **blurhash**.
 *
 * È la ventina di caratteri che descrive un'immagine come poche macchie di
 * colore. Il riquadro della card si riempie subito con quelle, e la locandina
 * vera ci scivola sopra quando arriva: niente rettangolo grigio, e soprattutto
 * niente salto di layout (§11.11) — il posto era già occupato.
 *
 * Si calcola su un raster minuscolo, non sull'originale: il blurhash è fatto
 * di quattro macchie per tre, e leggere due milioni di pixel per ricavarne
 * dodici sarebbe tempo di coda buttato.
 */
final class BlurhashEncoder
{
    /**
     * La stringa blurhash di un file, o `null` se l'immagine non è leggibile.
     */
    public function encode(string $path): ?string
    {
        $pixels = $this->sample($path);

        if ($pixels === null) {
            return null;
        }

        return Blurhash::encode(
            $pixels,
            config()->integer('media.blurhash.components_x'),
            config()->integer('media.blurhash.components_y'),
        );
    }

    /**
     * Il blurhash trasformato in una minuscola immagine PNG incorporata
     * nell'indirizzo (`data:`), che è la sola forma in cui un browser può
     * disegnarlo senza una libreria JavaScript.
     *
     * Si calcola qui, in coda, e si conserva accanto al blurhash: decodificarlo
     * a ogni disegno di pagina significherebbe rifare venti volte per pagina un
     * lavoro il cui risultato non cambia mai. Sono circa seicento byte, che
     * viaggiano dentro l'HTML e quindi arrivano insieme al testo — nessuna
     * richiesta in più, e il riquadro è pieno prima ancora che la locandina
     * parta.
     */
    public function placeholder(string $blurhash, int $width, int $height): ?string
    {
        $columns = 32;
        $rows = max(1, (int) round($columns * ($height / max($width, 1))));

        $pixels = Blurhash::decode($blurhash, $columns, $rows);
        $raster = imagecreatetruecolor($columns, $rows);

        if ($raster === false) {
            return null;
        }

        foreach ($pixels as $y => $row) {
            foreach ($row as $x => $pixel) {
                imagesetpixel($raster, (int) $x, (int) $y, imagecolorallocate(
                    $raster,
                    (int) $pixel[0],
                    (int) $pixel[1],
                    (int) $pixel[2],
                ));
            }
        }

        ob_start();
        imagepng($raster, null, 9);
        $png = ob_get_clean();
        imagedestroy($raster);

        if ($png === false || $png === '') {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * Le righe di pixel del campione ridotto, nella forma
     * `[riga][colonna] = [r, g, b]` che `kornrunner/blurhash` si aspetta.
     *
     * Il passaggio intermedio da PNG non è un giro inutile: è ciò che permette
     * di leggere con GD anche un originale HEIC, che GD non aprirebbe mai —
     * a ridurlo è Imagick, dentro `spatie/image`.
     *
     * @return list<list<array{0: int, 1: int, 2: int}>>|null
     */
    private function sample(string $path): ?array
    {
        $size = config()->integer('media.blurhash.sample');
        $temporary = tempnam(sys_get_temp_dir(), 'blurhash').'.png';

        try {
            Image::useImageDriver(config()->string('media-library.image_driver'))
                ->loadFile($path)
                ->width($size)
                ->format('png')
                ->save($temporary);

            $raster = @imagecreatefrompng($temporary);

            if ($raster === false) {
                return null;
            }

            $width = imagesx($raster);
            $height = imagesy($raster);
            $rows = [];

            for ($y = 0; $y < $height; $y++) {
                $row = [];

                for ($x = 0; $x < $width; $x++) {
                    $color = imagecolorat($raster, $x, $y);

                    $row[] = [
                        ($color >> 16) & 0xFF,
                        ($color >> 8) & 0xFF,
                        $color & 0xFF,
                    ];
                }

                $rows[] = $row;
            }

            imagedestroy($raster);

            return $rows;
        } catch (Throwable) {
            return null;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
