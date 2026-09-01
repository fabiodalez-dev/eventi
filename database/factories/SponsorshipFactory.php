<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Models\Event;
use App\Models\Sponsorship;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sponsorship>
 */
class SponsorshipFactory extends Factory
{
    protected $model = Sponsorship::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $event = Event::factory();

        return [
            'event_id' => $event,
            /* La citta' viene dall'evento: una campagna in una citta' diversa
               da quella del proprio evento non e' un caso da coprire, e' un
               dato incoerente. */
            'city_id' => fn (array $attributi): int => (int) Event::query()->whereKey($attributi['event_id'])->value('city_id'),
            'placement' => SponsorshipPlacement::ListTop,
            'status' => SponsorshipStatus::Active,
            'starts_at' => CarbonImmutable::now('UTC')->subDay(),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(30),
            'priority' => 0,
            'advertiser_name' => $this->faker->company(),
            'advertiser_email' => $this->faker->safeEmail(),
            'currency' => 'EUR',
            /* Dichiarati anche se il database ha gia' un valore predefinito:
               senza, l'oggetto appena creato li ha a `null` in memoria e ogni
               calcolo su di essi va storto finche' non lo si rilegge. */
            'impressions' => 0,
            'clicks' => 0,
        ];
    }

    /** Una campagna che non e' ancora cominciata. */
    public function future(): static
    {
        return $this->state([
            'starts_at' => CarbonImmutable::now('UTC')->addWeek(),
            'ends_at' => CarbonImmutable::now('UTC')->addWeeks(3),
        ]);
    }

    /** Una campagna finita. */
    public function expired(): static
    {
        return $this->state([
            'starts_at' => CarbonImmutable::now('UTC')->subMonth(),
            'ends_at' => CarbonImmutable::now('UTC')->subDay(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(['status' => SponsorshipStatus::Draft]);
    }

    public function paused(): static
    {
        return $this->state(['status' => SponsorshipStatus::Paused]);
    }

    public function placement(SponsorshipPlacement $placement): static
    {
        return $this->state(['placement' => $placement]);
    }
}
