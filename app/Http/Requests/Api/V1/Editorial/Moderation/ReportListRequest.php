<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Moderation;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Services\Editorial\ModerationDesk;
use Illuminate\Validation\Rule;

/** `GET /api/v1/editorial/reports`: filtri `status`, `reason`, `open` (1), ordinamento per data di arrivo. */
final class ReportListRequest extends ModerationListRequest
{
    protected function sorts(): array
    {
        return ModerationDesk::REPORT_SORTS;
    }

    protected function filterRules(): array
    {
        return [
            'filter.status' => ['nullable', Rule::enum(ReportStatus::class)],
            'filter.reason' => ['nullable', Rule::enum(ReportReason::class)],
            'filter.open' => ['nullable', 'boolean'],
        ];
    }
}
