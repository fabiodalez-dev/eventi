<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'platform' => DevicePlatform::Web,
            'push_token' => null,
            'endpoint' => 'https://push.example.org/'.Str::random(40),
            'keys' => ['p256dh' => Str::random(87), 'auth' => Str::random(22)],
            'app_version' => null,
            'locale' => 'it',
            'last_seen_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function mobile(DevicePlatform $platform = DevicePlatform::Android): static
    {
        return $this->state(fn (array $attributes): array => [
            'platform' => $platform,
            'push_token' => Str::random(160),
            'endpoint' => null,
            'keys' => null,
            'app_version' => '1.0.0',
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }

    public function dormant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_seen_at' => now()->subMonths(4),
        ]);
    }
}
