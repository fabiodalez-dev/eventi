<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Redirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Redirect>
 */
class RedirectFactory extends Factory
{
    protected $model = Redirect::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(3);

        return [
            'city_id' => null,
            'from_path' => '/eventi/'.$slug,
            'to_path' => '/eventi/'.$slug.'-nuovo',
            'is_wildcard' => false,
            'status' => 301,
        ];
    }
}
