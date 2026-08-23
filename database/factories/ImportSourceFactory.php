<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportSourceType;
use App\Models\Category;
use App\Models\City;
use App\Models\ImportSource;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportSource>
 */
class ImportSourceFactory extends Factory
{
    protected $model = ImportSource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'venue_id' => null,
            'type' => ImportSourceType::Ics,
            'url' => 'https://'.fake()->domainName().'/eventi.ics',
            'credentials' => null,
            'mapping' => null,
            'default_category_id' => null,
            'is_active' => true,
            'last_run_at' => null,
            'last_status' => null,
            'last_error' => null,
        ];
    }

    public function forVenue(?Venue $venue = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'venue_id' => $venue?->getKey() ?? Venue::factory()->approved(),
        ]);
    }

    public function ofType(ImportSourceType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }

    public function withCredentials(): static
    {
        return $this->state(fn (array $attributes): array => [
            'credentials' => fake()->sha256(),
        ]);
    }

    public function withDefaultCategory(?Category $category = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'default_category_id' => $category?->getKey() ?? Category::factory(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function failing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_run_at' => now()->subHours(2),
            'last_status' => 'error',
            'last_error' => 'HTTP 404 sul calendario remoto',
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_run_at' => now()->subHour(),
            'last_status' => 'ok',
            'last_error' => null,
        ]);
    }
}
