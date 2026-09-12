<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\User;
use App\Support\SecurityLog;
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

        /*
         * Un token vive novanta giorni e apre l'account intero: chi se lo
         * prende ha tutto, esportazione dei dati compresa. Il minimo è che la
         * sua emissione lasci una riga — nome del dispositivo e scadenza, mai
         * il token.
         */
        SecurityLog::scrivi('token_emesso', $user, [
            'dispositivo' => $name,
            'scade' => $expiresAt?->toIso8601String(),
        ], $user);

        return [
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }
}
