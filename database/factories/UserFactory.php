<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * La password condivisa fra tutti gli utenti generati, per non pagare
     * un hash bcrypt per riga.
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'timezone' => 'Europe/Rome',
            'locale' => 'it',
            'notification_preferences' => null,
            'daily_digest_time' => null,
            'quiet_hours' => null,
            'marketing_opt_in_at' => null,
            'last_active_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Utente che riceve il riepilogo giornaliero alle 17:00 e non vuole
     * essere disturbato di notte (§15.4).
     */
    public function withDigest(string $time = '17:00:00'): static
    {
        return $this->state(fn (array $attributes): array => [
            'daily_digest_time' => $time,
            'quiet_hours' => ['from' => '23:30', 'to' => '08:00'],
            'notification_preferences' => [
                'reminder_24h' => true,
                'reminder_3h' => true,
                'daily_digest' => true,
                'weekend_digest' => true,
            ],
        ]);
    }

    public function marketingOptedIn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'marketing_opt_in_at' => now(),
        ]);
    }

    public function dormant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_active_at' => now()->subYear(),
        ]);
    }

    /**
     * Referente di un locale: entra nella pivot `venue_user` come owner.
     */
    public function owning(Venue $venue): static
    {
        return $this->afterCreating(function (User $user) use ($venue): void {
            $user->venues()->attach($venue, [
                'role' => VenueRole::Owner->value,
                'invited_at' => now(),
                'accepted_at' => now(),
            ]);
        });
    }
}
