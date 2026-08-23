<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\User;
use App\Support\Api\ApiDate;

/**
 * L'utente autenticato, e nient'altro di lui.
 *
 * Niente ruoli, niente locali gestiti, niente conteggi: §16 impone di
 * raccogliere e restituire il minimo. L'email c'è perché è l'identificativo
 * con cui si accede.
 */
final class UserResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(User $user): array
    {
        $timezone = (string) $user->timezone;

        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => (string) $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'timezone' => $timezone,
            'locale' => (string) $user->locale,
            'marketing_opt_in' => $user->marketing_opt_in_at !== null,
            'created_at' => ApiDate::attribute($user, 'created_at', $timezone),
            'updated_at' => ApiDate::attribute($user, 'updated_at', $timezone),
        ];
    }
}
