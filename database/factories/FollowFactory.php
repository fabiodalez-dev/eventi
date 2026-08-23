<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FollowableType;
use App\Models\Category;
use App\Models\Follow;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Follow>
 */
class FollowFactory extends Factory
{
    protected $model = Follow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'followable_type' => FollowableType::Venue->value,
            'followable_id' => Venue::factory(),
            'notify' => true,
        ];
    }

    public function forVenue(?Venue $venue = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'followable_type' => FollowableType::Venue->value,
            'followable_id' => $venue?->getKey() ?? Venue::factory(),
        ]);
    }

    public function forTag(?Tag $tag = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'followable_type' => FollowableType::Tag->value,
            'followable_id' => $tag?->getKey() ?? Tag::factory(),
        ]);
    }

    public function forCategory(?Category $category = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'followable_type' => FollowableType::Category->value,
            'followable_id' => $category?->getKey() ?? Category::factory(),
        ]);
    }

    public function silent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'notify' => false,
        ]);
    }
}
