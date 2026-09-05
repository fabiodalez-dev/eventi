<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Carbon\CarbonImmutable;

final class SyncRequest extends ApiRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'since' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'days' => ['nullable', 'integer', 'between:1,365'],
        ];
    }

    public function since(): ?CarbonImmutable
    {
        $value = $this->validated('since');

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    public function days(): int
    {
        $value = $this->validated('days');

        return is_numeric($value) ? (int) $value : 90;
    }
}
