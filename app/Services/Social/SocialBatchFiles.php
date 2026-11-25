<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialFormat;
use App\Exceptions\SocialStudioException;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * I file del Social Studio che si scaricano: l'anteprima di una serata, le
 * immagini di un pacchetto e lo ZIP con immagini e didascalie. Li servono il
 * sito (`SocialDownloadController`) e l'app di gestione
 * (`SocialStudioController`), con le stesse regole e senza nuove copie sul
 * disco: lo ZIP nasce in un file temporaneo che si cancella dopo l'invio.
 */
final class SocialBatchFiles
{
    /**
     * L'anteprima JPEG della grafica di una serata, in formato verticale,
     * tenuta un'ora in cache con l'impronta della serata nella chiave.
     *
     * @throws SocialStudioException se la grafica non si può disegnare (il titolo non ci sta)
     */
    public function preview(EventOccurrence $occurrence): string
    {
        $key = 'social-preview-'.SocialGraphic::version().'-'.$occurrence->id.'-'.SocialSource::fingerprint($occurrence);

        return Cache::remember($key, 3600, fn () => app(SocialGraphic::class)->render($occurrence, SocialFormat::Portrait));
    }

    /** Il percorso assoluto di un'immagine del pacchetto, oppure `null` se non c'è (o non c'è più). */
    public function image(SocialBatch $batch, int $index): ?string
    {
        $item = $batch->items[$index] ?? null;
        if (! is_array($item) || ! Storage::disk('local')->exists($item['path'])) {
            return null;
        }

        return Storage::disk('local')->path($item['path']);
    }

    /** Il nome di un'immagine del pacchetto, come nello ZIP. */
    public function imageName(SocialBatch $batch, int $index): string
    {
        return (string) ($batch->items[$index]['filename'] ?? sprintf('%02d.jpg', $index + 1));
    }

    /**
     * Lo ZIP del pacchetto in un file temporaneo: le immagini e la didascalia,
     * divise in cartelle da dieci (un carosello) quando sono più di dieci.
     * `null` se un'immagine manca dal disco.
     */
    public function zip(SocialBatch $batch): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'social-');
        if ($path === false) {
            throw new RuntimeException(__('social.storage_error'));
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException(__('social.storage_error'));
        }
        foreach ($batch->items as $i => $item) {
            if (! Storage::disk('local')->exists($item['path'])) {
                $zip->close();
                @unlink($path);

                return null;
            }
            $folder = count($batch->items) > 10 ? sprintf('carosello-%02d/', intdiv($i, 10) + 1) : '';
            $zip->addFile(Storage::disk('local')->path($item['path']), $folder.$item['filename']);
        }
        foreach (app(SocialPublisher::class)->captions($batch) as $part => $caption) {
            $folder = count($batch->items) > 10 ? sprintf('carosello-%02d/', $part + 1) : '';
            $zip->addFromString($folder.'didascalia.txt', $caption);
        }
        $zip->close();

        return $path;
    }

    /** «incitta-2026-11-28-portrait.zip». */
    public function zipName(SocialBatch $batch): string
    {
        return 'incitta-'.$batch->date->format('Y-m-d').'-'.$batch->format.'.zip';
    }
}
