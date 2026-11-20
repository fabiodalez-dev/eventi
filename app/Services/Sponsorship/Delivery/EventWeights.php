<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Config;

/**
 * Quale evento mostrare, dentro la stessa unità.
 *
 *     u_k = max(pavimento ; e^(−g_k ÷ giorni))
 *
 * `g_k` sono i giorni (con le frazioni) dalla prossima data dell'evento `k`.
 * Più la data è vicina, più l'evento esce; il pavimento (0,1) garantisce che
 * nessun evento resti a zero. Tre eventi fra 1, 6 e 20 giorni: 62%, 31%, 7%.
 *
 * Vale solo dentro un'unità: fra committenti diversi la vicinanza della data
 * non sposta spazio, decide ciò che hanno comprato.
 *
 * Un evento senza una data futura non compare. Se la pagina ha un contesto
 * (filtri, categoria), restano gli eventi che lo rispettano; se non ne resta
 * nessuno si torna a tutti, come fa oggi `forPlacement()`.
 *
 * Classe pura: il tempo entra come parametro.
 */
final class EventWeights
{
    public const DEFAULT_PROXIMITY_DAYS = 7.0;

    public const DEFAULT_FLOOR = 0.1;

    private readonly float $proximityDays;

    private readonly float $floor;

    public function __construct(
        #[Config('sponsorship.event_proximity_days', self::DEFAULT_PROXIMITY_DAYS)] float $proximityDays = self::DEFAULT_PROXIMITY_DAYS,
        #[Config('sponsorship.event_weight_floor', self::DEFAULT_FLOOR)] float $floor = self::DEFAULT_FLOOR,
    ) {
        $this->proximityDays = max(1 / 24, $proximityDays);
        $this->floor = min(1.0, max(0.0, $floor));
    }

    /** Il peso di un evento la cui prossima data è fra `$days` giorni. */
    public function proximity(float $days): float
    {
        return max($this->floor, exp(-max(0.0, $days) / $this->proximityDays));
    }

    /**
     * I pesi grezzi `u_k`, per gli eventi che possono comparire.
     *
     * Ogni evento porta la sua prossima data: `null`, o una data già passata,
     * lo esclude. Per un evento in corso si passa `$now`. `$context`, se c'è,
     * è l'elenco delle chiavi che rispettano la pagina.
     *
     * @param  array<array-key, CarbonInterface|null>  $nextDates
     * @param  list<array-key>|null  $context
     * @return array<array-key, float>
     */
    public function weights(array $nextDates, CarbonInterface $now, ?array $context = null): array
    {
        $weights = [];

        foreach ($nextDates as $key => $next) {
            if ($next === null || $next->lessThan($now)) {
                continue;
            }

            $weights[$key] = $this->proximity($now->diffInSeconds($next) / 86400);
        }

        if ($context !== null) {
            $inContext = array_intersect_key($weights, array_flip($context));

            if ($inContext !== []) {
                return $inContext;
            }
        }

        return $weights;
    }

    /**
     * Le quote degli eventi dentro l'unità: i pesi divisi per la loro somma.
     *
     * @param  array<array-key, CarbonInterface|null>  $nextDates
     * @param  list<array-key>|null  $context
     * @return array<array-key, float>
     */
    public function shares(array $nextDates, CarbonInterface $now, ?array $context = null): array
    {
        return self::normalise($this->weights($nextDates, $now, $context));
    }

    /**
     * Pesi che sommano a 1. Vuoto se non c'è niente da dividere.
     *
     * @param  array<array-key, float>  $weights
     * @return array<array-key, float>
     */
    public static function normalise(array $weights): array
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            return [];
        }

        return array_map(static fn (float $weight): float => $weight / $total, $weights);
    }

    /**
     * Una grandezza dell'unità come media degli eventi pesata sulle loro
     * quote: è così che l'affinità di chi guarda (`a_i`) passa dagli eventi
     * all'unità. Un evento senza valore conta `$default`.
     *
     * @param  array<array-key, float>  $shares  quote che sommano a 1
     * @param  array<array-key, int|float>  $values
     */
    public static function weightedMean(array $shares, array $values, float $default = 1.0): float
    {
        $total = 0.0;
        $mean = 0.0;

        foreach ($shares as $key => $share) {
            $total += $share;
            $mean += $share * (float) ($values[$key] ?? $default);
        }

        return $total > 0 ? $mean / $total : $default;
    }
}
