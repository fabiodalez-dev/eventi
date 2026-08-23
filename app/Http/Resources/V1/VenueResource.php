<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Venue;
use App\Support\Api\ApiDate;

/**
 * Il locale in due forme.
 *
 * `summary()` è il "venue ridotto" che §13.2 mette dentro ogni occorrenza:
 * nome, comune e coordinate, cioè quanto basta a scrivere una riga sotto il
 * titolo e a piantare un punto sulla mappa. `toArray()` è la scheda intera,
 * che serve alla pagina del locale e a `include=venue`.
 *
 * Le due forme condividono i campi: chi legge la lista e poi apre la scheda
 * non deve vedere il nome cambiare provenienza.
 */
final class VenueResource
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Venue $venue): array
    {
        return [
            'id' => (int) $venue->getKey(),
            'slug' => (string) $venue->slug,
            'name' => (string) $venue->name,
            'municipality' => (string) $venue->municipality,
            'lat' => (float) $venue->lat,
            'lng' => (float) $venue->lng,
            'is_verified' => (bool) $venue->is_verified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Venue $venue, string $timezone): array
    {
        return [
            ...self::summary($venue),
            'type' => $venue->type->value,
            'status' => $venue->status->value,
            'description' => $venue->description,
            'short_description' => $venue->short_description,
            'address' => (string) $venue->address,
            'address_extra' => $venue->address_extra,
            'postal_code' => $venue->postal_code,
            'province_code' => (string) $venue->province_code,
            'phone' => $venue->phone,
            'email' => $venue->email,
            'website' => $venue->website,
            'socials' => $venue->socials,
            'opening_hours' => $venue->opening_hours,
            'capacity' => $venue->capacity === null ? null : (int) $venue->capacity,
            'accessibility' => $venue->accessibility,
            'requires_membership' => (bool) $venue->requires_membership,
            'membership_notes' => $venue->membership_notes,
            'cover' => self::cover($venue),
            'updated_at' => ApiDate::attribute($venue, 'updated_at', $timezone),
        ];
    }

    private static function cover(Venue $venue): ?string
    {
        $url = $venue->getFirstMediaUrl('cover');

        return $url === '' ? null : $url;
    }
}
