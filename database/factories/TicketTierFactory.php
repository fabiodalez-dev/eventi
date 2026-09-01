<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketTierStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\TicketTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketTier>
 */
class TicketTierFactory extends Factory
{
    protected $model = TicketTier::class;

    /** @var list<string> */
    private const NAMES = [
        'Intero', 'Ridotto under 26', 'Ridotto soci', 'Posto unico',
        'Platea numerata', 'Galleria', 'Prevendita', 'Cassa la sera stessa',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'occurrence_id' => null,
            'name' => fake()->randomElement(self::NAMES),
            'price' => fake()->randomFloat(2, 5, 60),
            'currency' => 'EUR',
            'status' => TicketTierStatus::Available,
            'url' => null,
            'note' => null,
            'sort_order' => 0,
        ];
    }

    public function soldOut(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TicketTierStatus::SoldOut,
        ]);
    }

    public function notYetOnSale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TicketTierStatus::NotYetOnSale,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TicketTierStatus::Closed,
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Ingresso libero',
            'price' => 0,
        ]);
    }

    /**
     * Il listino di **una data soltanto**: sostituisce quello dell'evento.
     */
    public function forOccurrence(EventOccurrence $occurrence): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $occurrence->event_id,
            'occurrence_id' => $occurrence->getKey(),
        ]);
    }
}
