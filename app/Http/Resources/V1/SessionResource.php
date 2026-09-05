<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Support\Api\ApiDate;
use Laravel\Sanctum\PersonalAccessToken;

final class SessionResource
{
    /** @return array<string, mixed> */
    public static function toArray(PersonalAccessToken $token, string $timezone, ?int $currentId): array
    {
        return [
            'id' => (int) $token->getKey(),
            'name' => (string) $token->name,
            'device_id' => is_numeric($token->getAttribute('device_id')) ? (int) $token->getAttribute('device_id') : null,
            'current' => (int) $token->getKey() === $currentId,
            'last_used_at' => ApiDate::attribute($token, 'last_used_at', $timezone),
            'expires_at' => ApiDate::attribute($token, 'expires_at', $timezone),
            'created_at' => ApiDate::attribute($token, 'created_at', $timezone),
        ];
    }
}
