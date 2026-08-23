<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\EventOccurrence;
use App\Models\ScheduledNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledNotification>
 */
class ScheduledNotificationFactory extends Factory
{
    protected $model = ScheduledNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['reminder_24h', 'reminder_3h', 'daily_digest', 'weekend_digest']);

        return [
            'user_id' => User::factory(),
            'notifiable_type' => null,
            'notifiable_id' => null,
            'type' => $type,
            'channel' => NotificationChannel::Mail,
            'send_at' => now()->addHours(3),
            'sent_at' => null,
            'status' => NotificationStatus::Pending,
            // La forma della chiave è quella di §7.10: tipo, utente, oggetto.
            'dedupe_key' => $type.':user_'.fake()->unique()->numberBetween(1, 999999).':occ_'.fake()->numberBetween(1, 999999),
            'payload' => null,
            'attempts' => 0,
            'last_error' => null,
        ];
    }

    /**
     * Promemoria legato a una singola occorrenza salvata.
     */
    public function forOccurrence(?EventOccurrence $occurrence = null): static
    {
        return $this->state(function (array $attributes) use ($occurrence): array {
            $occurrence ??= EventOccurrence::factory()->create();

            return [
                'notifiable_type' => $occurrence->getMorphClass(),
                'notifiable_id' => $occurrence->getKey(),
                'dedupe_key' => $attributes['type'].':user_'.fake()->unique()->numberBetween(1, 999999).':occ_'.$occurrence->getKey(),
            ];
        });
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
            'attempts' => 1,
        ]);
    }

    /**
     * Saltata: quiet hours o frequency cap (§15.6).
     */
    public function skipped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NotificationStatus::Skipped,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NotificationStatus::Failed,
            'attempts' => 3,
            'last_error' => 'SMTP connection timed out',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NotificationStatus::Cancelled,
        ]);
    }

    public function ofChannel(NotificationChannel $channel): static
    {
        return $this->state(fn (array $attributes): array => [
            'channel' => $channel,
        ]);
    }
}
