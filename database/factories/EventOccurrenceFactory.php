<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventOccurrence>
 *
 * `business_date` ed `effective_ends_at` sono NOT NULL e vengono valorizzati qui
 * in forma elementare (data di `starts_at`, fine a +180 minuti). La regola vera —
 * cutoff notturno della città e durata di categoria — appartiene a
 * `EventOccurrenceObserver`, che li ricalcola al salvataggio appena esisterà.
 */
class EventOccurrenceFactory extends Factory
{
    protected $model = EventOccurrence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::instance(fake()->dateTimeBetween('-1 month', '+2 months'))
            ->setTime(fake()->randomElement([18, 19, 20, 21, 22]), fake()->randomElement([0, 30]));

        return [
            'event_id' => Event::factory(),
            'recurrence_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => null,
            'effective_ends_at' => $startsAt->addMinutes(180),
            'doors_at' => $startsAt->subMinutes(30),
            'is_all_day' => false,
            'business_date' => $startsAt->toDateString(),
            'status' => OccurrenceStatus::Scheduled,
            'status_note' => null,
            'price_override' => null,
            'capacity_left' => null,
            'is_exception' => false,
        ];
    }

    /**
     * Occorrenza ancorata a un istante preciso: è la forma che serve ai test
     * del motore temporale.
     */
    public function startingAt(CarbonImmutable $startsAt, ?CarbonImmutable $endsAt = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'effective_ends_at' => $endsAt ?? $startsAt->addMinutes(180),
            'doors_at' => $startsAt->subMinutes(30),
            'business_date' => $startsAt->toDateString(),
        ]);
    }

    public function allDay(): static
    {
        return $this->state(function (array $attributes): array {
            $startsAt = CarbonImmutable::parse($attributes['starts_at'])->startOfDay();

            return [
                'starts_at' => $startsAt,
                'ends_at' => null,
                'effective_ends_at' => $startsAt->endOfDay(),
                'doors_at' => null,
                'is_all_day' => true,
                'business_date' => $startsAt->toDateString(),
            ];
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OccurrenceStatus::Cancelled,
            'status_note' => 'Annullato per maltempo.',
        ]);
    }

    public function soldOut(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OccurrenceStatus::SoldOut,
            'capacity_left' => 0,
        ]);
    }

    public function postponed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OccurrenceStatus::Postponed,
            'status_note' => 'Rinviato a data da destinarsi.',
        ]);
    }

    /**
     * Data che si discosta dalla serie ricorrente a cui appartiene.
     */
    public function exception(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_exception' => true,
        ]);
    }
}
