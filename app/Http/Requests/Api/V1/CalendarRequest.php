<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Carbon\CarbonImmutable;

/**
 * `GET /v1/calendar` (§13.1): quante date cadono in ciascun giorno del mese e
 * i primi titoli di ciascuno.
 *
 * Il mese si chiede come `2026-09`; senza, è quello corrente **nella città**,
 * non sull'orologio del server.
 */
final class CalendarRequest extends ApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    public function month(string $timezone): CarbonImmutable
    {
        $value = $this->validated('month');

        if (! is_string($value)) {
            return CarbonImmutable::now($timezone)->startOfMonth()->startOfDay();
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value.'-01 00:00:00', $timezone)->startOfMonth();
    }
}
