<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Enums\VerificationStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /** @var list<string> */
    private const TITLES = [
        'Concerto di %s', 'Presentazione del libro "%s"', 'Assemblea pubblica su %s',
        'Proiezione di %s', 'Serata %s', 'Laboratorio di %s', 'Mostra fotografica %s',
        'Torneo di %s', 'Festa di %s', 'Reading su %s',
    ];

    /** @var list<string> */
    private const SUBJECTS = [
        'musica popolare', 'poesia contemporanea', 'diritto alla casa', 'cinema d\'autore',
        'liscio', 'ceramica', 'periferie', 'scacchi', 'quartiere', 'migrazioni',
        'autoproduzione', 'fumetto', 'clima', 'cucina veneta',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = sprintf(fake()->randomElement(self::TITLES), fake()->randomElement(self::SUBJECTS));

        return [
            'city_id' => City::factory(),
            // Il locale nasce nella stessa città dell'evento, altrimenti la
            // scheda mostrerebbe un indirizzo fuori città.
            'venue_id' => fn (array $attributes) => Venue::factory()->approved()->state([
                'city_id' => $attributes['city_id'],
            ]),
            'category_id' => Category::factory(),
            'created_by' => User::factory(),
            'organizer_name' => null,
            'organizer_url' => null,
            'title' => $title,
            'subtitle' => fake()->boolean(30) ? fake()->sentence(6) : null,
            'description' => fake()->paragraphs(3, true),
            'short_description' => fake()->sentence(14),
            'poster' => null,
            'gallery' => null,
            'price_type' => PriceType::Unknown,
            'price_min' => null,
            'price_max' => null,
            'currency' => 'EUR',
            'price_notes' => null,
            'ticket_url' => null,
            'booking_required' => false,
            'booking_url' => null,
            'booking_phone' => null,
            'age_restriction' => null,
            'language' => 'it',
            'is_outdoor' => false,
            'custom_location' => null,
            'external_links' => null,
            'facts' => null,
            'source' => EventSource::Manual,
            'source_ref' => null,
            'verification_status' => VerificationStatus::Unverified,
            'status' => EventStatus::Draft,
            'rejection_reason' => null,
            'is_featured' => false,
            'featured_until' => null,
            'editorial_score' => 0,
            'published_at' => null,
            'seo' => null,
            'views_count' => 0,
            'saves_count' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventStatus::Pending,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventStatus::Rejected,
            'rejection_reason' => fake()->sentence(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventStatus::Cancelled,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventStatus::Archived,
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_featured' => true,
            'featured_until' => now()->addWeek(),
            'editorial_score' => fake()->numberBetween(50, 100),
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes): array => [
            'price_type' => PriceType::Free,
            'price_min' => 0,
            'price_max' => 0,
        ]);
    }

    public function ticketed(): static
    {
        $price = fake()->numberBetween(5, 35);

        return $this->state(fn (array $attributes): array => [
            'price_type' => PriceType::Ticket,
            'price_min' => $price,
            'price_max' => $price + fake()->randomElement([0, 5, 10]),
            'ticket_url' => fake()->url(),
        ]);
    }

    /**
     * Ingresso riservato ai soci: tipico di circoli e centri sociali.
     */
    public function membership(): static
    {
        return $this->state(fn (array $attributes): array => [
            'price_type' => PriceType::Membership,
            'price_notes' => 'Ingresso con tessera associativa.',
        ]);
    }

    /**
     * Evento senza locale registrato: piazza, corteo, sagra.
     */
    public function withoutVenue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'venue_id' => null,
            'is_outdoor' => true,
            'organizer_name' => fake()->company(),
            'custom_location' => [
                'name' => 'Prato della Valle',
                'address' => 'Prato della Valle, Padova',
                'lat' => 45.3987,
                'lng' => 11.8760,
            ],
        ]);
    }

    public function imported(): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => EventSource::ImportIcs,
            'source_ref' => fake()->uuid(),
            'created_by' => null,
        ]);
    }

    public function verifiedByVenue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verification_status' => VerificationStatus::VenueConfirmed,
            'source' => EventSource::Venue,
        ]);
    }
}
