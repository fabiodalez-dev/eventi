<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Quante occasioni avrà uno spazio, ora per ora, in una settimana tipo.
 *
 * È la previsione che serve al pacing (`Pacer`) e alla stima che la redazione
 * vede nel modulo. Non legge il database: riceve la serie che `TrafficHistory`
 * ha già letto, e restituisce le 168 ore della settimana (indice 0 = lunedì
 * dalle 0 alle 1, indice 167 = domenica dalle 23 alle 24, ora locale).
 *
 * **Con almeno `forecast_min_days` giorni di dati** ogni ora della settimana è
 * la media pesata della stessa ora nelle ultime settimane, la più recente con
 * peso più alto: `v̂ = (8·s1 + 4·s2 + 2·s3 + 1·s4) ÷ 15`. Con meno di quattro
 * settimane complete i pesi si riducono a quelle che ci sono
 * (`(8·s1 + 4·s2) ÷ 12` con due).
 *
 * **Con meno giorni** la stima è «provvisoria»: la forma è la curva di
 * partenza (picchi a pranzo e la sera, venerdì e sabato un quarto in più) e il
 * livello è la media giornaliera dei giorni misurati. **Senza nessun dato** il
 * livello è il valore di configurazione, che è un'ipotesi e non una misura.
 */
final class TrafficForecast
{
    public const HOURS_PER_WEEK = 168;

    /** Pesi delle ultime quattro settimane, dalla più recente. */
    public const DEFAULT_WEIGHTS = [8, 4, 2, 1];

    public const DEFAULT_MIN_DAYS = 14;

    /**
     * La forma di un giorno, dalle 0 alle 24: notte quasi vuota, picco a
     * pranzo (12–14) e picco più alto la sera (18–21). Sono proporzioni, non
     * visite: si normalizzano. È la curva della simulazione del piano.
     */
    public const STARTING_HOURLY_CURVE = [
        1.5, 0.8, 0.4, 0.3, 0.3, 0.5, 1.2, 2.5, 4.0, 4.5, 4.5, 5.0,
        6.5, 6.5, 5.0, 4.5, 5.0, 6.0, 7.5, 8.5, 8.5, 7.0, 5.0, 3.0,
    ];

    /** Il peso dei giorni, da lunedì a domenica: venerdì e sabato +25%. */
    public const STARTING_DAY_FACTORS = [1.0, 1.0, 1.0, 1.0, 1.25, 1.25, 1.0];

    /** Il 1° gennaio 1970 era un giovedì: 72 ore dopo il lunedì a mezzanotte. */
    private const EPOCH_HOUR_OF_WEEK = 72;

    private readonly float $weeklyTotal;

    /** @var list<float> le visite previste prima dell'ora `i` della settimana */
    private readonly array $prefix;

    /**
     * @param  list<float>  $profile
     */
    private function __construct(
        private readonly array $profile,
        private readonly int $days,
        private readonly bool $provisional,
    ) {
        $prefix = [];
        $sum = 0.0;

        foreach ($profile as $value) {
            $prefix[] = $sum;
            $sum += $value;
        }

        $this->prefix = $prefix;
        $this->weeklyTotal = $sum;
    }

    /**
     * La previsione dalla serie di `TrafficHistory::weekly()`.
     *
     * @param  array{days: int, weeks: list<array<int, int|float>|null>}  $history
     * @param  float  $defaultDailyViews  le visite al giorno da usare senza nessun dato
     * @param  list<int|float>  $weights  pesi delle settimane, dalla più recente
     * @param  int  $minDays  sotto questi giorni la stima è provvisoria
     */
    public static function fromHistory(array $history, float $defaultDailyViews, array $weights = self::DEFAULT_WEIGHTS, int $minDays = self::DEFAULT_MIN_DAYS): self
    {
        $days = max(0, $history['days']);
        $weeks = $history['weeks'];

        if ($days >= $minDays) {
            /* Solo le settimane complete: con 17 giorni di dati la terza
               settimana ne ha tre, e pesarla come intera abbasserebbe la stima. */
            $usable = min(count($weights), count($weeks), intdiv($days, 7));
            $profile = array_fill(0, self::HOURS_PER_WEEK, 0.0);
            $weightSum = 0.0;

            for ($week = 0; $week < $usable; $week++) {
                if ($weeks[$week] === null) {
                    continue;
                }

                $weight = (float) $weights[$week];
                $weightSum += $weight;

                for ($hour = 0; $hour < self::HOURS_PER_WEEK; $hour++) {
                    $profile[$hour] += $weight * (float) ($weeks[$week][$hour] ?? 0);
                }
            }

            if ($weightSum > 0) {
                $average = [];

                foreach ($profile as $value) {
                    $average[] = $value / $weightSum;
                }

                return new self($average, $days, false);
            }
        }

        if ($days > 0) {
            $total = 0.0;

            foreach ($weeks as $week) {
                foreach ($week ?? [] as $views) {
                    $total += (float) $views;
                }
            }

            return self::startingCurve($total / $days, $days);
        }

        return self::startingCurve($defaultDailyViews);
    }

    /**
     * La curva di partenza scalata su una media giornaliera: la settimana
     * somma sette volte `$dailyViews`. Sempre provvisoria.
     */
    public static function startingCurve(float $dailyViews, int $days = 0): self
    {
        $hourSum = array_sum(self::STARTING_HOURLY_CURVE);
        $daySum = array_sum(self::STARTING_DAY_FACTORS);
        $weekViews = max(0.0, $dailyViews) * 7;
        $profile = [];

        foreach (self::STARTING_DAY_FACTORS as $dayFactor) {
            foreach (self::STARTING_HOURLY_CURVE as $hourShare) {
                $profile[] = $weekViews * ($dayFactor / $daySum) * ($hourShare / $hourSum);
            }
        }

        return new self($profile, max(0, $days), true);
    }

    /** Lo stesso traffico in ogni ora: comodo per i conti a mano. */
    public static function flat(float $dailyViews): self
    {
        return new self(array_fill(0, self::HOURS_PER_WEEK, max(0.0, $dailyViews) / 24), 0, true);
    }

    /**
     * Una settimana scritta ora per ora.
     *
     * @param  array<int, int|float>  $profile  168 valori, dal lunedì alle 0
     */
    public static function fromProfile(array $profile, int $days = 0, bool $provisional = false): self
    {
        if (count($profile) !== self::HOURS_PER_WEEK) {
            throw new InvalidArgumentException('A weekly profile has exactly 168 hours.');
        }

        return new self(array_map(static fn (int|float $views): float => max(0.0, (float) $views), array_values($profile)), max(0, $days), $provisional);
    }

    /**
     * La previsione di più spazi insieme: un'unità che compare in più città
     * ha un obiettivo solo, e il traffico su cui spalmarlo è la somma.
     *
     * Ora per ora, quindi le città devono avere lo stesso fuso (le città del
     * progetto sono tutte italiane). I giorni di dati sono quelli della città
     * che ne ha meno, ed è provvisoria se lo è una sola delle previsioni.
     *
     * @param  list<self>  $forecasts
     */
    public static function sum(array $forecasts): self
    {
        if ($forecasts === []) {
            throw new InvalidArgumentException('A sum needs at least one forecast.');
        }

        if (count($forecasts) === 1) {
            return $forecasts[0];
        }

        $profile = array_fill(0, self::HOURS_PER_WEEK, 0.0);
        $days = PHP_INT_MAX;
        $provisional = false;

        foreach ($forecasts as $forecast) {
            foreach ($forecast->profile as $hour => $views) {
                $profile[$hour] += $views;
            }

            $days = min($days, $forecast->days);
            $provisional = $provisional || $forecast->provisional;
        }

        return new self($profile, $days, $provisional);
    }

    /** L'ora della settimana di un istante, nel suo fuso: 0 = lunedì dalle 0 alle 1. */
    public static function hourOfWeek(CarbonInterface $instant): int
    {
        return ($instant->dayOfWeekIso - 1) * 24 + $instant->hour;
    }

    /** Le visite previste in un'ora della settimana. */
    public function hourly(int $hourOfWeek): float
    {
        return $this->profile[(($hourOfWeek % self::HOURS_PER_WEEK) + self::HOURS_PER_WEEK) % self::HOURS_PER_WEEK];
    }

    /** @return list<float> le 168 ore */
    public function profile(): array
    {
        return $this->profile;
    }

    public function weeklyTotal(): float
    {
        return $this->weeklyTotal;
    }

    public function dailyAverage(): float
    {
        return $this->weeklyTotal / 7;
    }

    /** I giorni di dati su cui è calcolata: zero vuol dire «solo il valore di partenza». */
    public function days(): int
    {
        return $this->days;
    }

    /** Vero sotto `forecast_min_days` giorni di dati: la stima va presentata come provvisoria. */
    public function isProvisional(): bool
    {
        return $this->provisional;
    }

    /**
     * Le visite previste fra due istanti, contando le frazioni di ora.
     *
     * Le ore si contano sull'orologio da muro del fuso di `$from` e di `$to`
     * (quello della città): il giorno del cambio d'ora conta 24 ore della
     * settimana tipo anche se ne dura 23 o 25. Lo scarto è un'ora di notte,
     * la meno trafficata.
     */
    public function between(CarbonInterface $from, CarbonInterface $to): float
    {
        /* Le visite cumulate crescono con il tempo: all'indietro, o fra due
           istanti uguali, la differenza è zero o negativa e diventa zero. */
        return max(0.0, $this->cumulative($to->getTimestamp() + $to->getOffset()) - $this->cumulative($from->getTimestamp() + $from->getOffset()));
    }

    /** Le visite previste dal lunedì 29 dicembre 1969 a mezzanotte fino a un istante da muro. */
    private function cumulative(int $wallSeconds): float
    {
        /* Divisioni per difetto anche prima del 1970: l'ora che contiene un
           istante comincia sempre prima di lui. */
        $epochHours = (int) floor($wallSeconds / 3600);
        $seconds = $wallSeconds - $epochHours * 3600;
        $hours = $epochHours + self::EPOCH_HOUR_OF_WEEK;
        $weeks = (int) floor($hours / self::HOURS_PER_WEEK);
        $hour = $hours - $weeks * self::HOURS_PER_WEEK;

        return $weeks * $this->weeklyTotal + $this->prefix[$hour] + $this->profile[$hour] * $seconds / 3600;
    }
}
