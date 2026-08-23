<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\City;
use App\Support\Api\ApiDate;

/**
 * Una città con i parametri che governano il suo tempo e la sua mappa.
 *
 * `night_cutoff_time` e `starting_soon_minutes` viaggiano perché l'app possa
 * **dire** all'utente che cosa significa "stasera" in questa città, non per
 * calcolarlo: le finestre le decide `EventOccurrenceQuery` (§8.1).
 */
final class CityResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(City $city): array
    {
        return [
            'id' => (int) $city->getKey(),
            'slug' => (string) $city->slug,
            'name' => (string) $city->name,
            'province_code' => $city->province_code,
            'province_name' => $city->province_name,
            'region' => $city->region,
            'country_code' => (string) $city->country_code,
            'timezone' => (string) $city->timezone,
            'locale' => (string) $city->locale,
            'center' => [
                'lat' => (float) $city->center_lat,
                'lng' => (float) $city->center_lng,
            ],
            'default_zoom' => (int) $city->default_zoom,
            'bounds' => $city->bounds,
            'radius_km' => (int) $city->radius_km,
            'night_cutoff_time' => (string) $city->night_cutoff_time,
            'starting_soon_minutes' => (int) $city->starting_soon_minutes,
            'updated_at' => ApiDate::attribute($city, 'updated_at', (string) $city->timezone),
        ];
    }
}
