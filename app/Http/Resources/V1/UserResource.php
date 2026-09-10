<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Api\ApiDate;

/**
 * L'utente autenticato, e nient'altro di lui.
 *
 * L'etichetta del ruolo serve a riconoscere il proprio profilo nell'app.
 * Non espone permessi o locali gestiti e non autorizza operazioni.
 */
final class UserResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(User $user): array
    {
        $timezone = (string) $user->timezone;
        $role = collect([UserRole::SuperAdmin, UserRole::Admin, UserRole::Moderator, UserRole::VenueOwner, UserRole::VenueEditor])
            ->first(fn (UserRole $role): bool => $user->hasRole($role), UserRole::User);

        return [
            'id' => (int) $user->getKey(),
            'name' => $user->name,
            'email' => (string) $user->email,
            'role_label' => $role->label(),
            'email_verified' => $user->email_verified_at !== null,
            'timezone' => $timezone,
            'locale' => (string) $user->locale,
            'appearance' => $user->appearance,
            'marketing_opt_in' => $user->marketing_opt_in_at !== null,
            'created_at' => ApiDate::attribute($user, 'created_at', $timezone),
            'updated_at' => ApiDate::attribute($user, 'updated_at', $timezone),
        ];
    }
}
