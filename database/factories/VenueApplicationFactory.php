<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Enums\VenueType;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VenueApplication>
 */
class VenueApplicationFactory extends Factory
{
    protected $model = VenueApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'venue_id' => null,
            'venue_name' => 'Circolo '.fake()->lastName(),
            'contact_name' => fake()->name(),
            'contact_role' => fake()->randomElement(['Presidente', 'Gestore', 'Responsabile eventi', 'Socio']),
            'contact_phone' => fake()->numerify('+39 3## ######'),
            'contact_email' => fake()->companyEmail(),
            'address' => fake()->streetAddress().', Padova',
            'type' => fake()->randomElement(VenueType::cases()),
            'socials' => null,
            'message' => fake()->paragraph(),
            'documents' => null,
            'status' => ApplicationStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'notes' => null,
        ];
    }

    /**
     * Pratica accolta: il locale esiste e la pratica lo referenzia (§7.3).
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ApplicationStatus::Approved,
            'venue_id' => Venue::factory()->approved(),
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ApplicationStatus::Rejected,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'notes' => 'Documentazione insufficiente.',
        ]);
    }

    /**
     * Pratica il cui utente ha cancellato l'account (§15.9): resta a archivio.
     */
    public function orphaned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
        ]);
    }
}
