<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;
use App\Services\Social\SocialBatchFiles;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Anteprima, immagini e ZIP del Social Studio sul sito. I file li prepara
 * `SocialBatchFiles`, lo stesso dell'app di gestione.
 */
class SocialDownloadController extends Controller
{
    public function __construct(private readonly SocialBatchFiles $files) {}

    public function preview(EventOccurrence $occurrence): Response
    {
        Gate::authorize('update', $occurrence->event);
        try {
            $bytes = $this->files->preview($occurrence);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=300']);
    }

    public function image(SocialBatch $batch, int $index): BinaryFileResponse
    {
        $path = $this->files->image($batch, $index);
        abort_if($path === null, 404);

        return response()->file($path, ['Content-Type' => 'image/jpeg', 'X-Robots-Tag' => 'noindex', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function zip(SocialBatch $batch): BinaryFileResponse
    {
        Gate::authorize('view', $batch);
        try {
            $path = $this->files->zip($batch);
        } catch (\RuntimeException) {
            abort(500);
        }
        abort_if($path === null, 404);

        return response()->download($path, $this->files->zipName($batch))->deleteFileAfterSend();
    }
}
