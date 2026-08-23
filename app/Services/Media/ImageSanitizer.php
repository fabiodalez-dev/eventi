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
