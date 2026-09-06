<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\SocialFormat;
use App\Http\Controllers\Controller;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;
use App\Services\Social\SocialGraphic;
use App\Services\Social\SocialPublisher;
use App\Services\Social\SocialSource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class SocialDownloadController extends Controller
{
    public function preview(EventOccurrence $occurrence): Response
    {
        Gate::authorize('update', $occurrence->event);
        $key = 'social-preview-'.SocialGraphic::version().'-'.$occurrence->id.'-'.SocialSource::fingerprint($occurrence);
        try {
            $bytes = Cache::remember($key, 3600, fn () => app(SocialGraphic::class)->render($occurrence, SocialFormat::Portrait));
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=300']);
    }

    public function image(SocialBatch $batch, int $index): BinaryFileResponse
    {
        $item = $batch->items[$index] ?? null;
        abort_if($item === null || ! Storage::disk('local')->exists($item['path']), 404);

        return response()->file(Storage::disk('local')->path($item['path']), ['Content-Type' => 'image/jpeg', 'X-Robots-Tag' => 'noindex', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function zip(SocialBatch $batch): BinaryFileResponse
    {
        Gate::authorize('view', $batch);
        $path = tempnam(sys_get_temp_dir(), 'social-');
        abort_if($path === false, 500);
        $zip = new ZipArchive;
        abort_unless($zip->open($path, ZipArchive::OVERWRITE) === true, 500);
        foreach ($batch->items as $i => $item) {
            abort_unless(Storage::disk('local')->exists($item['path']), 404);
            $folder = count($batch->items) > 10 ? sprintf('carosello-%02d/', intdiv($i, 10) + 1) : '';
            $zip->addFile(Storage::disk('local')->path($item['path']), $folder.$item['filename']);
        }
        foreach (app(SocialPublisher::class)->captions($batch) as $part => $caption) {
            $folder = count($batch->items) > 10 ? sprintf('carosello-%02d/', $part + 1) : '';
            $zip->addFromString($folder.'didascalia.txt', $caption);
        }
        $zip->close();

        return response()->download($path, 'incitta-'.$batch->date->format('Y-m-d').'-'.$batch->format.'.zip')->deleteFileAfterSend();
    }
}
