<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\System;

use App\Enums\ImportRunStatus;
use App\Http\Requests\Api\V1\Editorial\Moderation\ModerationListRequest;
use App\Services\Editorial\EditorialImportSources;
use Illuminate\Validation\Rule;

/** `GET /api/v1/editorial/import-sources/{source}/runs`: il filtro «Esito» del pannello e l'ordine per data. */
final class ImportRunListRequest extends ModerationListRequest
{
    protected function sorts(): array
    {
        return EditorialImportSources::RUN_SORTS;
    }

    protected function filterRules(): array
    {
        return [
            'filter.status' => ['nullable', Rule::enum(ImportRunStatus::class)],
        ];
    }
}
