<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use Illuminate\Container\Attributes\Config;
use InvalidArgumentException;

/**
 * Come si divide uno spazio fra le unità che possono comparire.
 *
 * **Fasce.** Le unità si raggruppano per priorità, dalla più alta. Ogni fascia
 * prende ciò che le serve dallo spazio rimasto, e quello che non usa scende
 * alla fascia sotto e alla fine al contenuto di riserva (quello redazionale).
 *
 * **Dentro una fascia.** Le unità a visualizzazioni prendono la loro quota
 * (`Pacer`), al massimo `quota_max`. Se insieme chiedono più dello spazio
 * rimasto, le quote si riducono in proporzione e quelle unità sono «a
 * rischio». Le unità a periodo si dividono lo spazio che resta in proporzione
 * al peso: se ci sono, la fascia lo usa tutto e sotto non scende niente, come
 * con la priorità di oggi.
 *
 * **Chi guarda.** `modulate()` applica i moltiplicatori della persona
 * (affinità, e nel secondo rilascio il tetto di frequenza):
 * `P(i) = b_i · m_i ÷ (Σ b·m + riserva)`. La riserva concorre con il suo spazio
 * e moltiplicatore 1. Fra committenti diversi non entra nient'altro: decide
 * ciò che hanno comprato.
 *
 * Classe pura: niente database, niente orologio, niente caso. L'estrazione
 * riceve il numero da fuori (`Draw`).
 */
final class QuotaAllocator
{
    /** La chiave del contenuto di riserva nelle probabilità. Nessuna unità può chiamarsi così. */
    public const RESERVE = 'reserve';

    private readonly float $quotaMax;

    public function __construct(
        #[Config('sponsorship.quota_max', Pacer::DEFAULT_QUOTA_MAX)] float $quotaMax = Pacer::DEFAULT_QUOTA_MAX,
    ) {
        $this->quotaMax = min(1.0, max(0.0, $quotaMax));
    }

    /**
     * Le quote di base, uguali per tutti i visitatori.
     *
     * Ogni unità ha una chiave (di solito `unitKey()`: `g:12`, `s:45`), una
     * priorità, un peso (1–100; sotto 1 vale 1, come oggi) e una quota: `null`
     * per le unità a periodo, la quota richiesta dal `Pacer` per quelle a
     * visualizzazioni (anche oltre il tetto o infinita: qui si limita e si
     * segnala).
     *
     * Le quote escono nell'ordine delle fasce, dalla priorità più alta, e
     * dentro la fascia nell'ordine ricevuto.
     *
     * @param  iterable<array{key: string, priority?: int, weight?: int|float, quota?: float|null}>  $units
     * @return array{shares: array<string, float>, reserve: float, at_risk: list<string>}
     */
    public function allocate(iterable $units): array
    {
        $bands = [];

        foreach ($units as $unit) {
            $key = $unit['key'];

            if ($key === self::RESERVE) {
                throw new InvalidArgumentException('The reserve key cannot name a unit.');
            }

            $bands[$unit['priority'] ?? 0][$key] = $unit;
        }

        krsort($bands, SORT_NUMERIC);

        $shares = [];
        $atRisk = [];
        $space = 1.0;

        foreach ($bands as $band) {
            $quotas = [];
            $weights = [];

            foreach ($band as $key => $unit) {
                $quota = $unit['quota'] ?? null;

                if ($quota === null) {
                    $weights[$key] = max(1.0, (float) ($unit['weight'] ?? 1));

                    continue;
                }

                if (is_nan($quota)) {
                    $quota = 0.0;
                }

                if ($quota > $this->quotaMax) {
                    $atRisk[] = $key;
                }

                $quotas[$key] = max(0.0, min($this->quotaMax, $quota));
            }

            $asked = array_sum($quotas);

            if ($asked > $space) {
                foreach ($quotas as $key => $quota) {
                    $quotas[$key] = $quota * $space / $asked;
                    $atRisk[] = $key;
                }

                $asked = $space;
            }

            $space = max(0.0, $space - $asked);
            $totalWeight = array_sum($weights);

            foreach ($band as $key => $unit) {
                $shares[$key] = array_key_exists($key, $quotas) ? $quotas[$key] : $space * $weights[$key] / $totalWeight;
            }

            if ($weights !== []) {
                $space = 0.0;
            }
        }

        return ['shares' => $shares, 'reserve' => $space, 'at_risk' => array_values(array_unique($atRisk))];
    }

    /**
     * Le probabilità per una persona: quote di base per moltiplicatori, più la
     * riserva con moltiplicatore 1. Sommano a 1 e nessuna è negativa. Un
     * moltiplicatore mancante vale 1, uno negativo o non finito vale 0 (l'unità
     * esce dall'estrazione). Se non resta niente da estrarre, tutto va alla
     * riserva.
     *
     * @param  array<string, float>  $shares
     * @param  array<string, int|float>  $multipliers
     * @return array<string, float> le unità nell'ordine ricevuto, poi `RESERVE`
     */
    public static function modulate(array $shares, float $reserve, array $multipliers = []): array
    {
        $products = [];

        foreach ($shares as $key => $share) {
            $multiplier = (float) ($multipliers[$key] ?? 1.0);
            $products[$key] = max(0.0, $share) * (is_finite($multiplier) ? max(0.0, $multiplier) : 0.0);
        }

        $products[self::RESERVE] = max(0.0, $reserve);
        $total = array_sum($products);

        if ($total <= 0) {
            return array_map(static fn (): float => 0.0, $shares) + [self::RESERVE => 1.0];
        }

        return array_map(static fn (float $product): float => $product / $total, $products);
    }

    /**
     * L'estrazione: la prima chiave la cui probabilità cumulata supera `$u`
     * (un numero in [0, 1) di `Draw::fraction()`). Se gli arrotondamenti
     * lasciano `$u` oltre la somma, vince l'ultima chiave con probabilità
     * positiva. `null` solo se nessuna ce l'ha.
     *
     * @param  array<string, float>  $probabilities
     */
    public static function pick(array $probabilities, float $u): ?string
    {
        $cumulative = 0.0;
        $last = null;

        foreach ($probabilities as $key => $probability) {
            if ($probability <= 0) {
                continue;
            }

            $cumulative += $probability;
            $last = $key;

            if ($u < $cumulative) {
                return (string) $key;
            }
        }

        return $last === null ? null : (string) $last;
    }
}
