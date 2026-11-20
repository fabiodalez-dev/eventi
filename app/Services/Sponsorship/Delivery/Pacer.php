<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use App\Enums\SponsorshipPacing;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Config;

/**
 * Quanta parte dello spazio chiede un'unità venduta a visualizzazioni.
 *
 *     quota = min(quota_max ; (G − D) ÷ V̂)
 *
 * `G` è l'obiettivo, `D` le consegnate, `V̂` le visite previste dello spazio da
 * adesso alla fine vera del periodo. È la probabilità con cui l'unità vince
 * ogni occasione: se è in ritardo il numeratore pesa di più e la quota sale, se
 * è in anticipo scende. Si ricalcola a ogni piano e si corregge da sola.
 *
 * La quota «richiesta» è il rapporto senza tetto: quando supera `quota_max`
 * l'unità è «a rischio», cioè ha comprato più traffico di quello che resta.
 * Con `pacing = asap` la richiesta è `quota_max` fissa fino all'obiettivo.
 *
 * Classe pura: niente database, niente orologio. Il tempo entra come
 * parametro.
 */
final class Pacer
{
    public const DEFAULT_QUOTA_MAX = 0.8;

    private readonly float $quotaMax;

    public function __construct(
        #[Config('sponsorship.quota_max', self::DEFAULT_QUOTA_MAX)] float $quotaMax = self::DEFAULT_QUOTA_MAX,
    ) {
        $this->quotaMax = min(1.0, max(0.0, $quotaMax));
    }

    public function quotaMax(): float
    {
        return $this->quotaMax;
    }

    /**
     * La quota richiesta, senza tetto.
     *
     * Zero quando l'obiettivo è raggiunto (o non c'è). Infinita quando manca
     * qualcosa ma non è previsto più traffico: nessuna quota basta. Non va
     * serializzata in JSON: chi la mostra usa `quota()` e `isAtRisk()`.
     */
    public function required(int $goal, int $delivered, float $remainingViews, SponsorshipPacing $pacing = SponsorshipPacing::Even): float
    {
        $missing = $goal - $delivered;

        if ($missing <= 0) {
            return 0.0;
        }

        if ($pacing === SponsorshipPacing::Asap) {
            return $this->quotaMax;
        }

        if ($remainingViews <= 0) {
            return INF;
        }

        return $missing / $remainingViews;
    }

    /** La quota da mettere nel piano: la richiesta, al massimo `quota_max`. */
    public function quota(int $goal, int $delivered, float $remainingViews, SponsorshipPacing $pacing = SponsorshipPacing::Even): float
    {
        return min($this->quotaMax, $this->required($goal, $delivered, $remainingViews, $pacing));
    }

    /**
     * La quota richiesta da `$now` alla fine vera `$end`, con le visite
     * previste da `$forecast`. A periodo finito non chiede più niente.
     */
    public function requiredUntil(int $goal, int $delivered, TrafficForecast $forecast, CarbonInterface $now, CarbonInterface $end, SponsorshipPacing $pacing = SponsorshipPacing::Even): float
    {
        if ($now->greaterThanOrEqualTo($end)) {
            return 0.0;
        }

        return $this->required($goal, $delivered, $forecast->between($now, $end), $pacing);
    }

    public function quotaUntil(int $goal, int $delivered, TrafficForecast $forecast, CarbonInterface $now, CarbonInterface $end, SponsorshipPacing $pacing = SponsorshipPacing::Even): float
    {
        return min($this->quotaMax, $this->requiredUntil($goal, $delivered, $forecast, $now, $end, $pacing));
    }

    /** Vero quando la quota richiesta supera il tetto: l'obiettivo non ci sta nel traffico che resta. */
    public function isAtRisk(float $required): bool
    {
        return $required > $this->quotaMax;
    }

    /**
     * La fine vera del periodo: la prima fra la fine della campagna e la fine
     * dell'ultima data coperta. Dopo l'ultima data l'unità non compare più
     * (`visible()`), quindi spalmare l'obiettivo oltre vorrebbe dire chiedere
     * visualizzazioni che non possono arrivare.
     */
    public static function trueEnd(CarbonInterface $endsAt, ?CarbonInterface $lastCoveredDateEnd): CarbonImmutable
    {
        $end = CarbonImmutable::instance($endsAt);

        if ($lastCoveredDateEnd === null) {
            return $end;
        }

        $last = CarbonImmutable::instance($lastCoveredDateEnd);

        return $last->lessThan($end) ? $last : $end;
    }
}
