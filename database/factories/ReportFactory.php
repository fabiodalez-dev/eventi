<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Event;
use App\Models\Report;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reportable_type' => (new Event)->getMorphClass(),
            'reportable_id' => Event::factory(),
            'reason' => fake()->randomElement(ReportReason::cases()),
            'note' => fake()->sentence(12),
            'reporter_email' => fake()->safeEmail(),
            'reporter_user_id' => null,
            'status' => ReportStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'resolution_note' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Segnalazione su un contenuto preciso ("for" è già preso da Factory).
     */
    public function about(Model $reportable): static
    {
        return $this->state(fn (array $attributes): array => [
            'reportable_type' => $reportable->getMorphClass(),
            'reportable_id' => $reportable->getKey(),
        ]);
    }

    public function forVenue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'reportable_type' => (new Venue)->getMorphClass(),
            'reportable_id' => Venue::factory(),
        ]);
    }

    public function fromUser(): static
    {
        return $this->state(fn (array $attributes): array => [
            'reporter_user_id' => User::factory(),
            'reporter_email' => null,
        ]);
    }

    public function reviewing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReportStatus::Reviewing,
            'reviewed_by' => User::factory(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReportStatus::Resolved,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'resolution_note' => 'Informazione corretta e verificata con il locale.',
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReportStatus::Dismissed,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'resolution_note' => 'Segnalazione infondata.',
        ]);
    }

    public function ofReason(ReportReason $reason): static
    {
        return $this->state(fn (array $attributes): array => [
            'reason' => $reason,
        ]);
    }
}
