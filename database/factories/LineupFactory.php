<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LineupRole;
use App\Models\EventOccurrence;
use App\Models\Lineup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lineup>
 */
class LineupFactory extends Factory
{
    protected $model = Lineup::class;

    /** @var list<string> */
    private const ACTS = [
        'I Ribelli del Bassanello', 'Trio Naviglio', 'Selecter Arcella', 'Le Sorelle Sile',
        'Quartetto Santa Sofia', 'Banda Portello', 'Dj Prato', 'Orchestra Piovego',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'occurrence_id' => EventOccurrence::factory(),
            'name' => fake()->randomElement(self::ACTS),
            'role' => LineupRole::Live,
            'starts_at' => null,
            'url' => null,
            'sort_order' => 0,
        ];
    }

    public function ofRole(LineupRole $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => $role,
        ]);
    }

    public function opening(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => LineupRole::Opening,
            'sort_order' => 0,
        ]);
    }

    public function headliner(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => LineupRole::Live,
            'sort_order' => 10,
            'url' => fake()->url(),
        ]);
    }
}
