<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubmissionStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSubmission>
 */
class EventSubmissionFactory extends Factory
{
    protected $model = EventSubmission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'venue_id' => null,
            'event_id' => null,
            'title' => 'Serata '.fake()->word(),
            'raw_text' => fake()->paragraphs(2, true),
            'poster' => null,
            'venue_hint' => 'Circolo '.fake()->lastName(),
            'starts_at_hint' => now()->addWeeks(2),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->safeEmail(),
            'status' => SubmissionStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'notes' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Proposta accolta: l'evento creato resta legato alla segnalazione.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SubmissionStatus::Approved,
            'event_id' => Event::factory()->published(),
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SubmissionStatus::Rejected,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'notes' => 'Evento fuori ambito.',
        ]);
    }
}
