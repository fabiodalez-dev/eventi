<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventViewDaily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventViewDaily>
 */
class EventViewDailyFactory extends Factory
{
    protected $model = EventViewDaily::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $views = fake()->numberBetween(10, 800);

        return [
            'event_id' => Event::factory(),
            'date' => now()->toDateString(),
            'views' => $views,
            'unique_views' => (int) round($views * 0.7),
            'direction_clicks' => fake()->numberBetween(0, 40),
            'ticket_clicks' => fake()->numberBetween(0, 40),
            'shares' => fake()->numberBetween(0, 20),
        ];
    }

    public function onDate(string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'date' => $date,
        ]);
    }
}
