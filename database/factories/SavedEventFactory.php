<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EventOccurrence;
use App\Models\SavedEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedEvent>
 */
class SavedEventFactory extends Factory
{
    protected $model = SavedEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'occurrence_id' => EventOccurrence::factory(),
            'reminder_sent_at' => null,
        ];
    }

    /**
     * Promemoria a 24 ore già inviato: serve ai test di idempotenza (§15.3).
     */
    public function reminded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'reminder_sent_at' => ['24h' => now()->toIso8601String()],
        ]);
    }
}
