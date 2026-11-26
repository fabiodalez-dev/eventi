<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Editorial;

use App\Services\Sponsorship\Delivery\PacingState;
use Carbon\CarbonImmutable;

/**
 * A che punto è un'unità di consegna: una campagna singola o un'abilitazione.
 *
 * Per le unità a periodo (`goal` nullo) non c'è uno stato: si dicono solo le
 * visualizzazioni consegnate. Per quelle a visualizzazioni `state` dice «in
 * linea», «in ritardo» e così via, con la stessa regola del motore
 * (`PacingState::at`).
 *
 * Solo lettura: la calcola `SponsorshipProgress`, i pannelli la mostrano.
 */
final readonly class UnitProgress
{
    public function __construct(
        public string $unitKey,
        public ?int $goal,
        public int $delivered,
        public ?PacingState $state,
        public float $expected,
        public ?float $requiredQuota,
        public int $deliveredToday,
        public int $daysLeft,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $timezone,
    ) {}

    public function hasGoal(): bool
    {
        return $this->goal !== null;
    }

    /** Consegnate su obiettivo, da 0 a 100; `null` per le unità a periodo. */
    public function percent(): ?float
    {
        if ($this->goal === null || $this->goal <= 0) {
            return null;
        }

        return min(100.0, max(0.0, $this->delivered / $this->goal * 100));
    }

    /** L'atteso a oggi, in percentuale dell'obiettivo: il segno sulla barra. */
    public function expectedPercent(): ?float
    {
        if ($this->goal === null || $this->goal <= 0) {
            return null;
        }

        return min(100.0, max(0.0, $this->expected / $this->goal * 100));
    }

    public function stateKey(): string
    {
        return $this->state === null ? 'period' : $this->state->value;
    }

    public function stateLabel(): string
    {
        return __('sponsorships.pacing_state.'.$this->stateKey());
    }

    /** Il colore di Filament per l'etichetta: grigio per le unità a periodo. */
    public function color(): string
    {
        return $this->state?->color() ?? 'gray';
    }

    /**
     * La quota richiesta, già limitata e scritta per chi legge: «52%», oppure
     * «oltre l'80%» quando il traffico che resta non basta.
     */
    public function quotaLabel(float $quotaMax): ?string
    {
        if ($this->requiredQuota === null) {
            return null;
        }

        if ($this->requiredQuota > $quotaMax) {
            return __('sponsorships.progress.quota_over', ['max' => number_format($quotaMax * 100, 0, ',', '.')]);
        }

        return number_format($this->requiredQuota * 100, 0, ',', '.').'%';
    }
}
