<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * Il set iniziale di §7.4, con i tre flag che governano il motore temporale.
     *
     * @var array<int, array{name: string, icon: string, duration: int|null, ongoing: bool, nightlife: bool}>
     */
    private const CATEGORIES = [
        ['name' => 'Musica dal vivo', 'icon' => 'music', 'duration' => 180, 'ongoing' => true, 'nightlife' => true],
        ['name' => 'DJ set e nightlife', 'icon' => 'disc', 'duration' => 300, 'ongoing' => true, 'nightlife' => true],
        ['name' => 'Teatro e danza', 'icon' => 'masks', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Cinema', 'icon' => 'film', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Arte e mostre', 'icon' => 'palette', 'duration' => null, 'ongoing' => false, 'nightlife' => false],
        ['name' => 'Libri e presentazioni', 'icon' => 'book', 'duration' => 90, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Politica e attivismo', 'icon' => 'megaphone', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Sport', 'icon' => 'trophy', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Food e sagre', 'icon' => 'utensils', 'duration' => 300, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Mercatini', 'icon' => 'store', 'duration' => 480, 'ongoing' => false, 'nightlife' => false],
        ['name' => 'Corsi e workshop', 'icon' => 'wrench', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Bambini e famiglie', 'icon' => 'balloon', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Comunità e assemblee', 'icon' => 'users', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
        ['name' => 'Altro', 'icon' => 'dots', 'duration' => 120, 'ongoing' => true, 'nightlife' => false],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var array{name: string, icon: string, duration: int|null, ongoing: bool, nightlife: bool} $category */
        $category = fake()->randomElement(self::CATEGORIES);

        return [
            'name' => $category['name'],
            'icon' => $category['icon'],
            'color' => fake()->hexColor(),
            'sort_order' => fake()->numberBetween(0, 20),
            'is_active' => true,
            'parent_id' => null,
            'default_duration_minutes' => $category['duration'],
            'supports_ongoing' => $category['ongoing'],
            'is_nightlife' => $category['nightlife'],
        ];
    }

    public function nightlife(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'DJ set e nightlife',
            'default_duration_minutes' => 300,
            'supports_ongoing' => true,
            'is_nightlife' => true,
        ]);
    }

    /**
     * Categoria che non compare mai in "In corso adesso" (es. mostre, mercatini).
     */
    public function withoutOngoing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'supports_ongoing' => false,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
