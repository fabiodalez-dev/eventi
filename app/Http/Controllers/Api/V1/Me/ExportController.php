<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Api\V1\Concerns\InteractsWithMe;
use App\Http\Controllers\Controller;
use App\Services\Account\AccountExport;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /v1/me/export` (§15.9): portabilità dei dati.
 *
 * Esce tutto ciò che la persona ha dato — profilo, salvataggi, follow,
 * dispositivi, archivio e cronaca degli invii — e nulla di derivato. La
 * risposta non si mette in cache: il gruppo di queste rotte non passa da
 * `CacheJsonResponse`, ed è voluto.
 */
final class ExportController extends Controller
{
    use InteractsWithMe;

    public function __invoke(Request $request, AccountExport $export): JsonResponse
    {
        return ApiResponse::item($export($this->user($request)));
    }
}
