<?php

declare(strict_types=1);

namespace App\Services\Community;

use GdImage;
use InvalidArgumentException;
use Throwable;

/**
 * La foto del profilo, ricodificata dal server.
 *
 * Ogni foto passa di qui prima di essere salvata, che arrivi dal sito o
 * dall'app: si decodifica il contenuto (non l'estensione, non il tipo
 * dichiarato), si raddrizza secondo l'orientamento della fotocamera, si
 * riduce a 512 px sul lato lungo e si riscrive in JPEG. Così spariscono EXIF,
 * coordinate, metadati e qualunque coda di byte appesa a un file che si
 * dichiara immagine, e il disco tiene una foto da poche decine di KB invece
 * dei megabyte usciti dal telefono.
 *
 * Una foto che non si legge non è mai una pagina d'errore: chi chiama riceve
 * un'eccezione con la frase da mostrare sotto il campo.
 */
final class AvatarImage
{
    /** Il lato lungo della foto salvata: l'avatar più grande del sito è 112 px, 512 bastano anche agli schermi densi. */
    public const SIZE = 512;

    private const QUALITY = 85;

    /**
     * I byte JPEG della foto pronta da salvare.
     *
     * @throws InvalidArgumentException con il messaggio per la persona
     */
    public function encode(string $path): string
    {
        $bytes = @file_get_contents($path);

        if (! is_string($bytes) || $bytes === '') {
            throw new InvalidArgumentException(__('community.avatar_errors.unreadable'));
        }

        if ($this->isAnimatedWebp($bytes)) {
            throw new InvalidArgumentException(__('community.avatar_errors.animated'));
        }

        $image = $this->decode($bytes);
        $image = $this->orient($image, $path);
        $image = $this->shrink($image);

        ob_start();
        $written = imagejpeg($image, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        if (! $written || $jpeg === '') {
            throw new InvalidArgumentException(__('community.avatar_errors.unreadable'));
        }

        return $jpeg;
    }

    /**
     * Una WebP animata dichiara l'animazione nell'intestazione estesa (VP8X,
     * secondo bit dei flag). GD non la sa leggere: invece del suo errore
     * generico si dice alla persona che cosa non va.
     */
    public function isAnimatedWebp(string $bytes): bool
    {
        return strlen($bytes) > 21
            && substr($bytes, 0, 4) === 'RIFF'
            && substr($bytes, 8, 4) === 'WEBP'
            && substr($bytes, 12, 4) === 'VP8X'
            && (ord($bytes[20]) & 0x02) === 0x02;
    }

    private function decode(string $bytes): GdImage
    {
        try {
            /* GD segnala un file che non riconosce con un avviso, che Laravel
               trasforma in eccezione: lo si cattura qui, così un file rovinato
               diventa un messaggio sotto il campo e non un errore 500. */
            $image = imagecreatefromstring($bytes);
        } catch (Throwable) {
            $image = false;
        }

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException(__('community.avatar_errors.unreadable'));
        }

        return $image;
    }

    /**
     * Le foto del telefono sono salvate di lato e raddrizzate da un'etichetta
     * EXIF: la ricodifica la butta via, quindi la rotazione va applicata prima.
     */
    private function orient(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        try {
            $exif = @exif_read_data($path);
        } catch (Throwable) {
            $exif = false;
        }

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if (! $rotated instanceof GdImage) {
            return $image;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        if ($rotated !== $image) {
            imagedestroy($image);
        }

        return $rotated;
    }

    /**
     * Riduce al lato lungo di 512 px su fondo bianco: un PNG trasparente
     * scritto in JPEG verrebbe altrimenti con lo sfondo nero.
     */
    private function shrink(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::SIZE / max($width, $height, 1));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        return $canvas;
    }
}
