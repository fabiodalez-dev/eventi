<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['reminder_24h', 'reminder_3h', 'daily_digest', 'weekend_digest']),
            'channel' => NotificationChannel::Mail,
            'sent_at' => now(),
            'opened_at' => null,
            'clicked_at' => null,
        ];
    }

    public function opened(): static
    {
        return $this->state(fn (array $attributes): array => [
            'opened_at' => now()->addMinutes(20),
        ]);
    }

    public function clicked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'opened_at' => now()->addMinutes(20),
            'clicked_at' => now()->addMinutes(22),
        ]);
    }
}
