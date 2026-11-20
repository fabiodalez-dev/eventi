<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Models\Organizer;
use App\Models\SponsorshipGrant;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un'abilitazione pagata e in corso, di un locale approvato, a periodo.
 *
 * Peso, priorità e distribuzione prendono i valori predefiniti del modello
 * (1, 0, distribuita). Per un prodotto a visualizzazioni c'è `goal()`, per
 * peso e priorità `weighted()`.
 *
 * @extends Factory<SponsorshipGrant>
 */
class SponsorshipGrantFactory extends Factory
{
    protected $model = SponsorshipGrant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venue_id' => Venue::factory()->approved(),
            'organizer_id' => null,
            'mode' => PromotionMode::Selected,
            'placement' => SponsorshipPlacement::ListTop,
            'enabled' => true,
            'complimentary' => false,
            'starts_at' => CarbonImmutable::now('UTC')->subDay(),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(30),
            'amount_cents' => 10000,
            'paid_at' => CarbonImmutable::now('UTC')->subDay(),
            'payment_method' => 'bank_transfer',
        ];
    }

    /** «Tutti gli eventi» del committente: le righe le crea la sincronizzazione. */
    public function automatic(): static
    {
        return $this->state(['mode' => PromotionMode::Automatic]);
    }

    /** Gratuita, con la motivazione che la regola chiede. */
    public function complimentary(): static
    {
        return $this->state([
            'complimentary' => true,
            'amount_cents' => null,
            'paid_at' => null,
            'payment_method' => null,
            'notes' => 'Gratuita per prova',
        ]);
    }

    public function placement(SponsorshipPlacement $placement): static
    {
        return $this->state(['placement' => $placement]);
    }

    /** Di un organizzatore invece che di un locale. */
    public function forOrganizer(Organizer $organizer): static
    {
        return $this->state(['venue_id' => null, 'organizer_id' => $organizer->getKey()]);
    }

    /** Prodotto a visualizzazioni: un obiettivo e una distribuzione. */
    public function goal(int $impressions, SponsorshipPacing $pacing = SponsorshipPacing::Even): static
    {
        return $this->state(['impressions_goal' => $impressions, 'pacing' => $pacing]);
    }

    /** Peso e priorità dell'unità. */
    public function weighted(int $weight, int $priority = 0): static
    {
        return $this->state(['weight' => $weight, 'priority' => $priority]);
    }
}
