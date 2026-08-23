<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use App\Enums\ImageType;
use App\Models\Event;
use App\Services\Media\BlurhashEncoder;
use App\Services\Media\ImageSanitizer;
use Illuminate\Support\Facades\Log;
use Spatie\Image\Image;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Throwable;

/**
 * La pipeline di §12.1 messa in fila, **tutta in coda e mai bloccante**:
 *
 * ```
 * validazione MIME reale → strip EXIF → resize → WebP + AVIF → blurhash
 * ```
 *
 * È il lavoro di conversione della libreria media, sostituito attraverso
 * `media-library.jobs.perform_conversions`. Sostituirlo invece di aggiungere
 * un secondo lavoro accanto è l'unico modo di garantire **l'ordine**: se lo
 * strip dell'EXIF girasse in parallelo alle conversioni, le varianti
 * potrebbero nascere dall'originale ancora sporco — Imagick riscrive i pixel
 * ma conserva i riquadri dei metadati — e la posizione GPS di chi ha
 * fotografato la locandina finirebbe pubblicata dentro la miniatura.
 *
 * La validazione è ripetuta qui anche se `App\Rules\RealImage` l'ha già fatta
 * sul modulo: dai moduli passano le persone, da qui passano anche l'importatore
 * e i comandi, e un file che arriva da un calendario altrui non ha compilato
 * nessun modulo.
 */
final class ProcessImageMedia extends PerformConversionsJob
{
    public function handle(FileManipulator $fileManipulator): bool
    {
        $media = $this->media;
        $path = $this->localPath();

        if ($path !== null) {
            if (! $this->isRealImage($path)) {
                return false;
            }

            if (app(ImageSanitizer::class)->sanitize($path)) {
                $media->size = (int) filesize($path);
                $media->save();
            }
        }

        parent::handle($fileManipulator);

        if ($path !== null) {
            $this->describe($path);
        }

        $model = $media->model;

        if ($model instanceof Event) {
            GenerateOpenGraphImage::dispatch($model);
        }

        return true;
    }

    /**
     * Il percorso locale dell'originale, o `null` se il disco non è locale.
     *
     * Su un disco remoto il file non si può riscrivere in posizione: la
     * pipeline si limita alle conversioni, che partono comunque da una copia
     * temporanea. È una constatazione, non una rinuncia — oggi il disco è
     * `public` e locale.
     */
    private function localPath(): ?string
    {
        if (config('filesystems.disks.'.$this->media->disk.'.driver') !== 'local') {
            return null;
        }

        $path = $this->media->getPath();

        return is_file($path) ? $path : null;
    }

    /**
     * Il file è davvero un'immagine di un formato accettato? Se non lo è, il
     * media viene rimosso: tenere sul disco pubblico un file di tipo ignoto,
     * caricato con l'estensione di un'immagine, è il modo in cui si finisce
     * per servirlo come qualcos'altro.
     */
    private function isRealImage(string $path): bool
    {
        if (ImageType::detect($path) !== null) {
            return true;
        }

        Log::warning('Media rifiutato: il contenuto non è un formato immagine accettato', [
            'media_id' => $this->media->getKey(),
            'collection' => $this->media->collection_name,
            'declared_mime' => $this->media->mime_type,
        ]);

        $this->media->delete();

        return false;
    }

    /**
     * Misure e blurhash, scritti nelle proprietà del media: sono ciò che
     * permette alla card di riservare il posto giusto prima che l'immagine
     * arrivi (§11.11) e di riempirlo con le macchie di colore invece che con
     * un rettangolo grigio.
     */
    private function describe(string $path): void
    {
        $width = null;
        $height = null;

        try {
            $image = Image::useImageDriver(config()->string('media-library.image_driver'))->loadFile($path);
            $width = $image->getWidth();
            $height = $image->getHeight();

            $this->media->setCustomProperty('width', $width);
            $this->media->setCustomProperty('height', $height);
        } catch (Throwable $exception) {
            Log::warning('Misure del media non leggibili', [
                'media_id' => $this->media->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }

        $encoder = app(BlurhashEncoder::class);
        $blurhash = $encoder->encode($path);

        if ($blurhash !== null) {
            $this->media->setCustomProperty('blurhash', $blurhash);
            $this->media->setCustomProperty('placeholder', $encoder->placeholder($blurhash, $width ?? 4, $height ?? 3));
        }

        $this->media->save();
    }
}
