<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\Promotion;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venue_id' => Venue::factory()->approved(),
            'event_id' => null,
            'type' => 'homepage',
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->addWeek()->endOfDay(),
            'notes' => null,
        ];
    }

    public function forEvent(?Event $event = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $event?->getKey() ?? Event::factory()->published(),
        ]);
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }
}
