<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\User;
use Carbon\CarbonImmutable;

final class MobileTokenIssuer
{
    /** @return array{token: string, expires_at: string|null} */
    public function issue(User $user, mixed $deviceName): array
    {
        $name = is_string($deviceName) && trim($deviceName) !== ''
            ? trim($deviceName)
            : __('api.auth.token_name');

        $days = config()->integer('api.auth.token_expiration_days');
        $expiresAt = $days > 0 ? CarbonImmutable::now()->addDays($days) : null;
        $token = $user->createToken($name, ['*'], $expiresAt);

        return [
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }
}
