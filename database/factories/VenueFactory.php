<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VenuePlan;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Models\City;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    protected $model = Venue::class;

    /** @var list<string> */
    private const PREFIXES = [
        'Circolo', 'Osteria', 'Teatro', 'Cinema', 'Libreria', 'Birreria',
        'Centro sociale', 'Bar', 'Sala', 'Spazio',
    ];

    /** @var list<string> */
    private const NAMES = [
        'Aurora', 'Portello', 'Prato della Valle', 'San Benedetto', 'Sant\'Antonio',
        'Le Torri', 'Belzoni', 'Naviglio', 'Santa Sofia', 'Arcella', 'Bassanello',
        'Mandria', 'Ponte Molino', 'Selva', 'Volta Barozzo',
    ];

    /** @var list<string> */
    private const MUNICIPALITIES = [
        'Padova', 'Abano Terme', 'Albignasego', 'Cadoneghe', 'Cittadella',
        'Este', 'Legnaro', 'Monselice', 'Noventa Padovana', 'Piove di Sacco',
        'Rubano', 'Selvazzano Dentro', 'Vigonza',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lat = fake()->randomFloat(7, 45.1500, 45.6500);
        $lng = fake()->randomFloat(7, 11.6000, 12.1000);

        return [
            'city_id' => City::factory(),
            'name' => fake()->randomElement(self::PREFIXES).' '.fake()->randomElement(self::NAMES),
            'type' => fake()->randomElement(VenueType::cases()),
            'description' => fake()->paragraphs(2, true),
            'short_description' => fake()->sentence(12),
            'address' => fake()->streetAddress(),
            'address_extra' => null,
            'postal_code' => fake()->numerify('35###'),
            'municipality' => fake()->randomElement(self::MUNICIPALITIES),
            'province_code' => 'PD',
            'lat' => $lat,
            'lng' => $lng,
            // La libreria vuole (latitudine, longitudine) e scrive POINT(lng lat)
            // con SRID 0: è l'ordine richiesto da §4 delle convenzioni.
            'location' => new Point($lat, $lng, 0),
            'phone' => fake()->numerify('+39 049 ######'),
            'email' => fake()->companyEmail(),
            'website' => fake()->url(),
            'socials' => ['instagram' => 'https://instagram.com/'.fake()->userName()],
            'opening_hours' => null,
            'capacity' => fake()->numberBetween(40, 800),
            'accessibility' => ['wheelchair' => fake()->boolean()],
            'requires_membership' => false,
            'membership_notes' => null,
            'status' => VenueStatus::Draft,
            'is_verified' => false,
            'is_nonprofit' => false,
            'plan' => VenuePlan::Free,
            'auto_publish' => false,
            'approved_at' => null,
            'approved_by' => null,
            'rejection_reason' => null,
            'claim_token' => null,
            'default_event_settings' => null,
            'stats_cache' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => VenueStatus::Approved,
            'approved_at' => now(),
            'approved_by' => User::factory(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => VenueStatus::Pending,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => VenueStatus::Rejected,
            'rejection_reason' => fake()->sentence(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => VenueStatus::Suspended,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_verified' => true,
        ]);
    }

    /**
     * Locale no-profit: gratuito per sempre secondo D9.
     */
    public function nonprofit(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_nonprofit' => true,
            'requires_membership' => true,
            'membership_notes' => 'Ingresso riservato ai soci, tesseramento in sede.',
            'plan' => VenuePlan::Free,
        ]);
    }

    public function premium(): static
    {
        return $this->state(fn (array $attributes): array => [
            'plan' => VenuePlan::Premium,
        ]);
    }

    /**
     * Locale di cui ci si fida: i suoi eventi saltano la coda di moderazione.
     */
    public function autoPublishing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'auto_publish' => true,
        ]);
    }

    public function claimable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'claim_token' => Str::random(48),
        ]);
    }

    public function ofType(VenueType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }

    /**
     * Coordinate esatte, per i test geospaziali.
     */
    public function at(float $lat, float $lng): static
    {
        return $this->state(fn (array $attributes): array => [
            'lat' => $lat,
            'lng' => $lng,
            'location' => new Point($lat, $lng, 0),
        ]);
    }
}
