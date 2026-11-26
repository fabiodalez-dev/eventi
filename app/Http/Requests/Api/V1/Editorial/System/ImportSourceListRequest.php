<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\System;

use App\Enums\ImportSourceType;
use App\Http\Requests\Api\V1\Editorial\Moderation\ModerationListRequest;
use App\Services\Editorial\EditorialImportSources;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/editorial/import-sources`: i filtri della tabella del pannello,
 * `filter[type]`, `filter[active]` (1 o 0), `filter[failed]` (1); ordinamento
 * per ultima esecuzione. Il pannello non ha ricerca su questa tabella.
 */
final class ImportSourceListRequest extends ModerationListRequest
{
    protected function sorts(): array
    {
        return EditorialImportSources::SORTS;
    }

    protected function filterRules(): array
    {
        return [
            'filter.type' => ['nullable', Rule::enum(ImportSourceType::class)],
            'filter.active' => ['nullable', 'boolean'],
            'filter.failed' => ['nullable', 'boolean'],
        ];
    }

    /** Il filtro sì/no a tre stati: `null` quando non è scelto. */
    public function activeFilter(): ?bool
    {
        $value = $this->filterValue('active');

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
