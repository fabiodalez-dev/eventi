<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Il tipo reale di un file immagine, letto dai suoi primi byte.
 *
 * §12.1 chiede la «validazione MIME reale». Reale vuol dire tre cose che non
 * coincidono mai per caso:
 *
 * 1. **non l'estensione** — `locandina.jpg` può essere uno script PHP, e il
 *    nome del file lo sceglie chi carica;
 * 2. **non il tipo dichiarato dal browser** — viaggia nella richiesta, quindi
 *    lo sceglie chi carica anche quello. Un iPhone manda spesso i propri HEIC
 *    come `application/octet-stream`;
 * 3. **nemmeno `finfo` da solo** — dipende dalla versione di libmagic
 *    installata sul server, e sui HEIC le versioni vecchie rispondono
 *    `application/octet-stream`. Un controllo che cambia risposta cambiando
 *    macchina non è un controllo.
 *
 * Restano i byte del file, che sono gli stessi ovunque. Questo enum riconosce
 * esattamente i formati che §12.1 accetta, e nient'altro: ciò che non
 * riconosce viene rifiutato, non "provato lo stesso".
 */
enum ImageType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Heic = 'image/heic';
    case Avif = 'image/avif';

    /**
     * Le prime marche ISO-BMFF che dichiarano un HEIC o un AVIF. Stanno nel
     * riquadro `ftyp`, che nei file di questa famiglia è il primo.
     */
    private const HEIF_BRANDS = ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1'];

    private const AVIF_BRANDS = ['avif', 'avis'];

    /**
     * Il tipo di un file dal suo contenuto, o `null` se non è nessuno dei
     * formati accettati.
     */
    public static function detect(string $path): ?self
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $header = (string) fread($handle, 32);
        fclose($handle);

        return self::fromHeader($header);
    }

    public static function fromHeader(string $header): ?self
    {
        if (strlen($header) < 12) {
            return null;
        }

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return self::Jpeg;
        }

        if (str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
            return self::Png;
        }

        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
            return self::Webp;
        }

        /*
         * ISO-BMFF: i primi quattro byte sono la lunghezza del riquadro, poi
         * viene il nome `ftyp` e subito dopo la marca principale. HEIC e AVIF
         * condividono il contenitore e si distinguono solo lì.
         */
        if (substr($header, 4, 4) === 'ftyp') {
            $brand = strtolower(substr($header, 8, 4));

            if (in_array($brand, self::AVIF_BRANDS, true)) {
                return self::Avif;
            }

            if (in_array($brand, self::HEIF_BRANDS, true)) {
                return self::Heic;
            }
        }

        return null;
    }

    /**
     * Le estensioni che questo tipo può legittimamente portare. Serve a
     * scartare il caso opposto a quello di sopra: un file che *è* davvero
     * un'immagine ma si presenta con un'estensione che il server tratterebbe
     * in un altro modo.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Jpeg => ['jpg', 'jpeg'],
            self::Png => ['png'],
            self::Webp => ['webp'],
            self::Heic => ['heic', 'heif'],
            self::Avif => ['avif'],
        };
    }

    /**
     * L'estensione con cui il file va conservato: una sola per tipo, così due
     * caricamenti dello stesso formato non producono due nomi diversi.
     */
    public function extension(): string
    {
        return $this->extensions()[0];
    }
}
