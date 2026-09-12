<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Models\User;
use App\Support\Api\ApiDate;

/**
 * L'utente autenticato, e nient'altro di lui.
 *
 * L'etichetta del ruolo serve a riconoscere il proprio profilo nell'app.
 * I collegamenti al pannello non autorizzano operazioni: ogni destinazione applica le proprie policy.
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
            'management_links' => self::managementLinks($user),
            'email_verified' => $user->email_verified_at !== null,
            'timezone' => $timezone,
            'locale' => (string) $user->locale,
            'appearance' => $user->appearance,
            'marketing_opt_in' => $user->marketing_opt_in_at !== null,
            'created_at' => ApiDate::attribute($user, 'created_at', $timezone),
            'updated_at' => ApiDate::attribute($user, 'updated_at', $timezone),
        ];
    }

    /** @return list<array{label: string, url: string, icon: string}> */
    private static function managementLinks(User $user): array
    {
        $links = [];
        if ($user->isEditorialStaff()) {
            $links[] = ['label' => 'Amministrazione', 'url' => url('/admin'), 'icon' => 'admin'];
        }
        if ($user->hasRole(UserRole::SuperAdmin)) {
            $links[] = ['label' => 'Impostazioni newsletter', 'url' => url('/admin/newsletter'), 'icon' => 'newsletter'];
        }
        if ($user->venues()->whereIn('venues.status', VenueStatus::valoriSenzaProvvedimento())->exists()) {
            $links[] = ['label' => 'Gestisci i locali', 'url' => url('/gestione'), 'icon' => 'venue'];
        }
        $organizer = $user->managedOrganizers()->exists();
        if ($organizer) {
            $links[] = ['label' => 'Gestisci gli eventi', 'url' => url('/organizza'), 'icon' => 'events'];
        }
        if ($organizer || $user->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin]) || $user->ownedVenues()->exists()) {
            $links[] = ['label' => 'Gestione biglietti', 'url' => route('ticketing.manage.index'), 'icon' => 'tickets'];
        }

        return $links;
    }
}
