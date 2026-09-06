<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;

final class ReleaseStatusController
{
    public function __invoke(): JsonResponse
    {
        $file = storage_path('app/private/release-status.json');
        $status = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return response()->json([
            'sha' => is_array($status) ? ($status['sha'] ?? null) : null,
            'deployed_at' => is_array($status) ? ($status['deployed_at'] ?? null) : null,
        ])->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex');
    }
}
