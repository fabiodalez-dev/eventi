<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Lineup;
use App\Support\Api\ApiDate;

final class LineupResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Lineup $lineup, string $timezone): array
    {
        return [
            'id' => (int) $lineup->getKey(),
            'name' => (string) $lineup->name,
            'role' => $lineup->role->value,
            'starts_at' => ApiDate::instant($lineup->starts_at, $timezone),
            'url' => $lineup->url,
        ];
    }
}
