<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

/**
 * Padova è la città pilota, attiva e con lo scope provinciale di D11.
 * Vicenza resta inattiva: dimostra che l'aggiunta di una città è un
 * `INSERT INTO cities`, non un deploy (§7.1 del piano).
 */
class CitySeeder extends Seeder
{
    public function run(): void
    {
        City::factory()->padova()->create([
            'name' => 'Padova',
            'slug' => 'padova',
            'province_code' => 'PD',
            'province_name' => 'Padova',
            'region' => 'Veneto',
            'country_code' => 'IT',
            'timezone' => 'Europe/Rome',
            'center_lat' => 45.4064,
            'center_lng' => 11.8768,
            'default_zoom' => 12,
            'radius_km' => 35,
            'night_cutoff_time' => '06:00:00',
            'starting_soon_minutes' => 180,
            'is_active' => true,
            'locale' => 'it',
        ]);

        City::factory()->create([
            'name' => 'Vicenza',
            'slug' => 'vicenza',
            'province_code' => 'VI',
            'province_name' => 'Vicenza',
            'region' => 'Veneto',
            'country_code' => 'IT',
            'timezone' => 'Europe/Rome',
            'center_lat' => 45.5455,
            'center_lng' => 11.5354,
            'default_zoom' => 12,
            'radius_km' => 20,
            'night_cutoff_time' => '06:00:00',
            'starting_soon_minutes' => 180,
            'is_active' => false,
            'locale' => 'it',
        ]);
    }
}
