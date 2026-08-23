<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * File immagine costruiti a mano per provare la pipeline di §12.1.
 *
 * L'EXIF si scrive byte per byte perché non c'è altro modo: ImageMagick,
 * interrogato, **non scrive** le proprietà `exif:*` in un JPEG, e GD non
 * conosce affatto i metadati. Provare uno strip su un file senza EXIF sarebbe
 * un test che passa sempre e non dimostra niente.
 *
 * Il riquadro APP1 contiene due sole voci — `Orientation` e `ResolutionUnit` —
 * scelte perché il loro valore sta nei quattro byte della voce stessa e non
 * richiede una tabella di rimandi: è l'EXIF valido più corto che si possa
 * scrivere.
 */
final class ImageFixtures
{
    /**
     * Un JPEG con dentro un riquadro EXIF, con l'orientamento richiesto.
     *
     * L'orientamento 6 vuol dire «ruotala di 90 gradi prima di mostrarla»: è
     * quello che scrive un telefono tenuto in verticale, ed è il caso in cui
     * togliere l'EXIF senza ruotare davvero i pixel coricherebbe l'immagine.
     */
    public static function jpegWithExif(int $width = 600, int $height = 800, int $orientation = 6): string
    {
        return self::withExif(self::jpeg($width, $height), $orientation);
    }

    public static function jpeg(int $width = 600, int $height = 800): string
    {
        $raster = imagecreatetruecolor($width, $height);

        /*
         * Un'immagine a tinta unita darebbe un blurhash degenere: le macchie
         * servono a distinguere un blurhash calcolato davvero da uno costante.
         */
        imagefilledrectangle($raster, 0, 0, $width, $height, (int) imagecolorallocate($raster, 210, 40, 90));
        imagefilledellipse($raster, (int) ($width / 3), (int) ($height / 3), (int) ($width / 2), (int) ($height / 2), (int) imagecolorallocate($raster, 40, 90, 210));

        ob_start();
        imagejpeg($raster, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($raster);

        return $bytes;
    }

    public static function png(int $width = 400, int $height = 400): string
    {
        $raster = imagecreatetruecolor($width, $height);
        imagefilledrectangle($raster, 0, 0, $width, $height, (int) imagecolorallocate($raster, 20, 160, 120));

        ob_start();
        imagepng($raster);
        $bytes = (string) ob_get_clean();
        imagedestroy($raster);

        return $bytes;
    }

    /**
     * Un file caricato come lo consegna un modulo, scritto su disco.
     */
    public static function upload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Innesta un riquadro APP1 subito dopo il marcatore di inizio immagine.
     */
    private static function withExif(string $jpeg, int $orientation): string
    {
        $tiff = 'II'                              // little endian
            ."\x2a\x00"                           // magic 42
            ."\x08\x00\x00\x00"                   // offset del primo IFD
            ."\x02\x00"                           // due voci
            ."\x12\x01"."\x03\x00"."\x01\x00\x00\x00".pack('v', $orientation)."\x00\x00"
            ."\x28\x01"."\x03\x00"."\x01\x00\x00\x00"."\x02\x00\x00\x00"
            ."\x00\x00\x00\x00";                  // nessun IFD successivo

        $payload = "Exif\x00\x00".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }
}
