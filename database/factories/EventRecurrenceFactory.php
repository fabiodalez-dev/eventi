<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventRecurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventRecurrence>
 */
class EventRecurrenceFactory extends Factory
{
    protected $model = EventRecurrence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'rrule' => 'FREQ=WEEKLY;BYDAY=FR',
            'until' => null,
            'exdates' => null,
            'generated_until' => null,
        ];
    }

    public function weekly(string $byDay = 'FR'): static
    {
        return $this->state(fn (array $attributes): array => [
            'rrule' => 'FREQ=WEEKLY;BYDAY='.$byDay,
        ]);
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rrule' => 'FREQ=MONTHLY;BYDAY=1SA',
        ]);
    }

    /**
     * Serie già materializzata per i prossimi 12 mesi (§7.8).
     */
    public function generated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'generated_until' => now()->addYear(),
        ]);
    }

    public function endingOn(string $until): static
    {
        return $this->state(fn (array $attributes): array => [
            'until' => $until,
        ]);
    }
}
