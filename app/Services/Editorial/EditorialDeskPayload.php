<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\City;
use App\Models\EditorialUnlock;
use App\Support\Editorial\EditorialBadges;
use App\Support\Editorial\EditorialDesk;

/**
 * La risposta di `GET /api/v1/editorial/desk`: chi è, dove lavora, che cosa
 * può fare, i contatori del menu e lo stato dello sblocco.
 */
final class EditorialDeskPayload
{
    public function __construct(
        private readonly EditorialPermissions $permissions,
        private readonly EditorialBadges $badges,
        private readonly EditorialUnlocks $unlocks,
    ) {}

    /** @return array<string, mixed> */
    public function for(EditorialDesk $desk, ?EditorialUnlock $unlock): array
    {
        return [
            'user' => ['id' => (int) $desk->user->getKey(), 'name' => (string) $desk->user->name],
            'role' => $desk->role->value,
            'city' => $desk->city instanceof City ? self::city($desk->city) : null,
            // Tutte, anche le spente: la redazione prepara una città prima di accenderla.
            'cities' => City::query()->orderByDesc('is_active')->orderBy('name')->get()->map(self::city(...))->values()->all(),
            'permissions' => $this->permissions->for($desk->user),
            'badges' => $this->badges->for($desk->user),
            'unlock' => $this->unlocks->state($unlock),
        ];
    }

    /** @return array{id: int, slug: string, name: string, timezone: string, is_active: bool} */
    public static function city(City $city): array
    {
        return [
            'id' => (int) $city->getKey(),
            'slug' => (string) $city->slug,
            'name' => (string) $city->name,
            'timezone' => (string) $city->timezone,
            'is_active' => (bool) $city->is_active,
        ];
    }
}
