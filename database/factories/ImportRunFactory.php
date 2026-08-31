<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Models\ImportSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRun>
 */
class ImportRunFactory extends Factory
{
    protected $model = ImportRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'import_source_id' => ImportSource::factory(),
            'status' => ImportRunStatus::Success->value,
            'created_count' => 0,
            'updated_count' => 0,
            'unchanged_count' => 0,
            'excluded_count' => 0,
            'cancelled_count' => 0,
            'error_count' => 0,
            'message' => null,
        ];
    }

    public function failed(string $message = 'Calendario irraggiungibile'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ImportRunStatus::Failed->value,
            'error_count' => 1,
            'message' => $message,
        ]);
    }

    public function partial(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ImportRunStatus::Partial->value,
            'created_count' => 3,
            'error_count' => 2,
            'message' => 'Due voci non interpretabili',
        ]);
    }
}
