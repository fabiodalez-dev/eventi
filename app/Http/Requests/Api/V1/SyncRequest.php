<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Carbon\CarbonImmutable;

final class SyncRequest extends ApiRequest
{
    /**
     * Quanto indietro si può chiedere che cosa è cambiato. Oltre, il client
     * rifà la sincronizzazione completa (senza `since`): una data remota a
     * piacere trasformava `deleted_ids` in una scansione dell'intera storia
     * della città, ripetuta a ogni richiesta.
     */
    public const MAX_SINCE_DAYS = 30;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'since' => ['nullable', 'date_format:Y-m-d\TH:i:sP', 'after_or_equal:'.now('UTC')->subDays(self::MAX_SINCE_DAYS)->toIso8601String()],
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
