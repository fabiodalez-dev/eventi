<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'value' => fake()->sentence(4),
            'type' => 'string',
            'group' => 'generale',
            'description' => fake()->sentence(8),
        ];
    }

    public function boolean(bool $value = true): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'boolean',
            'value' => $value ? '1' : '0',
        ]);
    }

    public function integer(int $value): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'integer',
            'value' => (string) $value,
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    public function json(array $value): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'json',
            'value' => json_encode($value),
        ]);
    }

    public function featureFlag(string $key, bool $enabled = false): static
    {
        return $this->state(fn (array $attributes): array => [
            'key' => $key,
            'type' => 'boolean',
            'value' => $enabled ? '1' : '0',
            'group' => 'feature_flag',
        ]);
    }
}
