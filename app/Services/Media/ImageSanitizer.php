<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Imagick;
use ImagickException;

/**
 * Il secondo anello della pipeline di §12.1: **strip EXIF**.
 *
 * Una locandina fotografata con un telefono porta con sé, dentro il file, la
 * posizione GPS di chi l'ha scattata, il numero di serie dell'apparecchio e
 * l'ora esatta. Pubblicarla senza toccarla significa pubblicare anche quello:
 * è un dato personale che nessuno ha chiesto di diffondere (§16).
 *
 * Due cautele che non sono dettagli:
 *
 * 1. **Prima si applica l'orientamento, poi si toglie.** Il riquadro EXIF
 *    `Orientation` è ciò che dice al visualizzatore di ruotare l'immagine di
 *    90°. Cancellarlo senza aver prima ruotato davvero i pixel fa uscire tutte
 *    le foto verticali coricate su un fianco — e succede solo con le foto da
 *    telefono, cioè quasi tutte le locandine.
 * 2. **Il profilo colore si conserva.** `stripImage()` porta via anche l'ICC,
 *    e senza profilo un'immagine in Display P3 viene mostrata come sRGB:
 *    i colori si spengono. Si estrae prima e si rimette dopo.
 *
 * Chi non ha Imagick non resta senza difesa: la pipeline riscrive comunque
 * ogni variante, e una riscrittura non porta con sé i riquadri EXIF
 * dell'originale.
 */
final class ImageSanitizer
{
    /**
     * Ripulisce il file **in posizione**. Risponde `true` se il file è stato
     * riscritto, `false` se non c'era modo di farlo.
     */
    public function sanitize(string $path): bool
    {
        if (! extension_loaded('imagick') || ! is_file($path) || ! is_writable($path)) {
            return false;
        }

        try {
            $image = new Imagick($path);

            $this->applyOrientation($image);

            /*
             * Il resize che §12.1 dichiara fra lo strip e le varianti, e che
             * non esisteva: il ridimensionamento avveniva soltanto *dentro* le
             * conversioni, e l'originale restava com'era arrivato.
             *
             * Si fa qui, dove l'immagine è già aperta e già in procinto di
             * essere riscritta: costa una chiamata, mentre in un passo a parte
             * costerebbe un'altra apertura e un'altra scrittura dello stesso
             * file.
             *
             * **Prima dello strip dei metadati, non dopo**, perché
             * l'orientamento è appena stato applicato ai pixel: ridimensionare
             * un'immagine ancora ruotata dall'EXIF produrrebbe un lato lungo
             * misurato sul verso sbagliato.
             */
            $this->limitaDimensione($image);

            $profiles = $image->getImageProfiles('icc', true);
            $icc = isset($profiles['icc']) && is_string($profiles['icc']) ? $profiles['icc'] : null;

            $image->stripImage();

            if ($icc !== null) {
                $image->profileImage('icc', $icc);
            }

            $image->writeImage($path);
            $image->clear();

            return true;
        } catch (ImagickException $exception) {
            /*
             * Un originale che Imagick non sa riaprire non è un motivo per
             * perdere il caricamento: le varianti verranno comunque generate
             * riscrivendo i pixel. Resta però una notizia da lasciare scritta,
             * perché un guasto silenzioso è quello che non si scopre mai.
             */
            Log::warning('Strip EXIF non riuscito', [
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Ruota davvero i pixel secondo il riquadro `Orientation`, così che
     * toglierlo non cambi ciò che si vede.
     */
    /**
     * Riporta il lato lungo entro `media.max_original_width`.
     *
     * **Perché l'originale e non solo le varianti.** La variante più grande è
     * 1600px: un originale da 6000 non serve a nessuno, non viene servito mai,
     * e occupa spazio su una quota che è già stata esaurita una volta. Le
     * conversioni partono da qui, quindi un originale più piccolo le rende
     * anche più veloci da generare.
     *
     * **`Fit::Max` in spirito: non si ingrandisce mai.** Una locandina
     * quadrata da 900px resta com'è — portarla a 2400 aggiungerebbe peso e
     * nessun dettaglio, perché i pixel che non c'erano non compaiono.
     */
    private function limitaDimensione(Imagick $image): void
    {
        $massimo = config()->integer('media.max_original_width');

        if ($massimo <= 0) {
            return;
        }

        $larghezza = $image->getImageWidth();
        $altezza = $image->getImageHeight();
        $latoLungo = max($larghezza, $altezza);

        if ($latoLungo <= $massimo) {
            return;
        }

        /*
         * Zero sul lato calcolato: è così che Imagick mantiene le proporzioni.
         * Passare entrambi i lati significherebbe deformare l'immagine ogni
         * volta che il rapporto non coincide.
         */
        $image->resizeImage(
            $larghezza >= $altezza ? $massimo : 0,
            $larghezza >= $altezza ? 0 : $massimo,
            Imagick::FILTER_LANCZOS,
            1,
        );
    }

    private function applyOrientation(Imagick $image): void
    {
        if (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();

            return;
        }

        $orientation = $image->getImageOrientation();
        $white = 'white';

        match ($orientation) {
            Imagick::ORIENTATION_TOPRIGHT => $image->flopImage(),
            Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage($white, 180),
            Imagick::ORIENTATION_BOTTOMLEFT => $image->flipImage(),
            Imagick::ORIENTATION_LEFTTOP => $this->transpose($image, 90),
            Imagick::ORIENTATION_RIGHTTOP => $image->rotateImage($white, 90),
            Imagick::ORIENTATION_RIGHTBOTTOM => $this->transpose($image, -90),
            Imagick::ORIENTATION_LEFTBOTTOM => $image->rotateImage($white, -90),
            default => null,
        };

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }

    private function transpose(Imagick $image, int $degrees): bool
    {
        $image->flopImage();

        return $image->rotateImage('white', $degrees);
    }
}
