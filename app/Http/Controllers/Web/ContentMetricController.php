<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\ConsentCategory;
use App\Enums\ContentMetric;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\ContentMetricRequest;
use App\Services\Analytics\RecordContentMetric;
use App\Support\Consent;
use Illuminate\Http\Response;

final class ContentMetricController extends Controller
{
    public function __invoke(ContentMetricRequest $request, string $type, int $id, Consent $consent, RecordContentMetric $record): Response
    {
        if ($consent->allows(ConsentCategory::Statistics)) {
            $record->record($type, $id, ContentMetric::from($request->validated('metric')), $request->query('occurrence') !== null ? (int) $request->query('occurrence') : null);
        }

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }
}
