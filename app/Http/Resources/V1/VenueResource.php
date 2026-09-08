<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Venue;
use App\Services\Seo\EditorialContent;
use App\Support\Api\ApiDate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
            // Il quartiere: più preciso del comune, ed è la scala a cui si
            // cerca dentro una città.
            'zone' => $venue->zone,
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
            'content_details' => app(EditorialContent::class)->details($venue),
            'address' => (string) $venue->address,
            'address_extra' => $venue->address_extra,
            'postal_code' => $venue->postal_code,
            'province_code' => (string) $venue->province_code,
            'phone' => $venue->phone,
            'email' => $venue->email,
            'website' => $venue->website,
            'socials' => (object) $venue->socials,
            'opening_hours' => $venue->opening_hours,
            // «Come arrivare»: lista di `{mode, text}`, dove `mode` è un
            // valore chiuso (`transit_mode` di §13.6) e non testo libero.
            'transit' => $venue->transit->toArray(),
            'capacity' => $venue->capacity === null ? null : (int) $venue->capacity,
            /*
             * Accessibilità **strutturata**: una mappa delle sole voci
             * dichiarate. Una chiave assente significa "non dichiarato", che
             * non è `false` — la differenza conta più qui che altrove.
             */
            'accessibility' => (object) $venue->accessibility->toArray(),
            'info' => $venue->info->toArray(),
            'requires_membership' => (bool) $venue->requires_membership,
            'membership_notes' => $venue->membership_notes,
            'cover' => self::cover($venue),
            'updated_at' => ApiDate::attribute($venue, 'updated_at', $timezone),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function cover(Venue $venue): ?array
    {
        $media = $venue->getFirstMedia('cover');

        if (! $media instanceof Media) {
            return null;
        }

        $full = $media->getFullUrl();

        return [
            'thumb' => $media->hasGeneratedConversion('thumb') ? $media->getFullUrl('thumb') : $full,
            'card' => $media->hasGeneratedConversion('card') ? $media->getFullUrl('card') : $full,
            'full' => $full,
            'blurhash' => $media->getCustomProperty('blurhash') ?: null,
            'width' => is_numeric($media->getCustomProperty('width')) ? (int) $media->getCustomProperty('width') : null,
            'height' => is_numeric($media->getCustomProperty('height')) ? (int) $media->getCustomProperty('height') : null,
        ];
    }
}
