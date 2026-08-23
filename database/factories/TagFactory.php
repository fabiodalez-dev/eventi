<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /** @var list<string> */
    private const TAGS = [
        'punk', 'hardcore', 'jazz', 'techno', 'house', 'cantautorato', 'open-mic',
        'jam-session', 'benefit', 'fiaccolata', 'assemblea', 'corteo', 'stand-up',
        'presentazione-libro', 'proiezione', 'torneo', 'laboratorio', 'vinyl-market',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(self::TAGS),
            'category_id' => null,
            'is_approved' => false,
            'usage_count' => 0,
            'synonyms' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_approved' => true,
        ]);
    }

    public function popular(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_approved' => true,
            'usage_count' => fake()->numberBetween(50, 500),
        ]);
    }

    public function forCategory(?Category $category = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'category_id' => $category?->getKey() ?? Category::factory(),
        ]);
    }
}
