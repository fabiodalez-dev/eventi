<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConsentAction;
use App\Enums\ConsentCategory;
use App\Models\ConsentLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConsentLog>
 */
class ConsentLogFactory extends Factory
{
    protected $model = ConsentLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consent_id' => (string) Str::uuid(),
            'user_id' => null,
            'action' => ConsentAction::RejectAll,
            'choices' => [
                ConsentCategory::Necessary->value => true,
                ConsentCategory::Statistics->value => false,
            ],
            'policy_version' => (string) config('consent.version'),
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'action' => ConsentAction::AcceptAll,
            'choices' => [
                ConsentCategory::Necessary->value => true,
                ConsentCategory::Statistics->value => true,
            ],
        ]);
    }
}
