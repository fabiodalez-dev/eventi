<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * @var array<int, array{name: string, province_code: string, province_name: string, lat: float, lng: float}>
     */
    private const CITIES = [
        ['name' => 'Padova', 'province_code' => 'PD', 'province_name' => 'Padova', 'lat' => 45.4064, 'lng' => 11.8768],
        ['name' => 'Vicenza', 'province_code' => 'VI', 'province_name' => 'Vicenza', 'lat' => 45.5455, 'lng' => 11.5354],
        ['name' => 'Treviso', 'province_code' => 'TV', 'province_name' => 'Treviso', 'lat' => 45.6669, 'lng' => 12.2433],
        ['name' => 'Verona', 'province_code' => 'VR', 'province_name' => 'Verona', 'lat' => 45.4384, 'lng' => 10.9916],
        ['name' => 'Rovigo', 'province_code' => 'RO', 'province_name' => 'Rovigo', 'lat' => 45.0705, 'lng' => 11.7902],
        ['name' => 'Belluno', 'province_code' => 'BL', 'province_name' => 'Belluno', 'lat' => 46.1400, 'lng' => 12.2160],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var array{name: string, province_code: string, province_name: string, lat: float, lng: float} $city */
        $city = fake()->randomElement(self::CITIES);

        return [
            'name' => $city['name'],
            'province_code' => $city['province_code'],
            'province_name' => $city['province_name'],
            'region' => 'Veneto',
            'country_code' => 'IT',
            'timezone' => 'Europe/Rome',
            'center_lat' => $city['lat'],
            'center_lng' => $city['lng'],
            'default_zoom' => 12,
            'bounds' => null,
            'radius_km' => 30,
            'locale' => 'it',
            'is_active' => false,
            'launched_at' => null,
            'night_cutoff_time' => '06:00:00',
            'starting_soon_minutes' => 180,
            'settings' => null,
        ];
    }

    /**
     * La città pilota del progetto: provincia di Padova, attiva (D11).
     */
    public function padova(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Padova',
            'province_code' => 'PD',
            'province_name' => 'Padova',
            'center_lat' => 45.4064,
            'center_lng' => 11.8768,
            'radius_km' => 35,
            'is_active' => true,
            'launched_at' => now(),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => true,
            'launched_at' => now(),
        ]);
    }
}
