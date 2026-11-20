<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\SponsorshipSlotHourly;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Il traffico passato di uno spazio, letto da `sponsorship_slot_hourly` e
 * messo nella forma che serve alla previsione (`TrafficForecast`, che il
 * database non lo legge).
 *
 * **La forma.** Per la previsione conta «la stessa ora della settimana nelle
 * ultime quattro settimane». `weekly()` restituisce quindi un elenco di
 * settimane, dalla più recente, e per ognuna le 168 ore della settimana:
 * l'indice 0 è il lunedì dalle 0 alle 1, l'indice 167 la domenica dalle 23
 * alle 24, sempre nell'ora locale della città.
 *
 * Le settimane sono finestre di sette giorni che finiscono all'inizio dell'ora
 * in corso: la settimana 0 sono le ultime 168 ore, la 1 le 168 prima, e così
 * via. Così il valore della settimana 0 per un'ora della settimana è sempre
 * l'ultima volta che quell'ora c'è stata.
 *
 * I canali (sito e app) si sommano, salvo che se ne chieda uno: uno spazio
 * venduto è lo stesso spazio da dove lo si guardi.
 */
final class TrafficHistory
{
    public const HOURS_PER_WEEK = 168;

    /**
     * Le ultime `$weeks` settimane di traffico dello spazio.
     *
     * - `weeks`: dalla più recente; `null` per una settimana senza nessuna
     *   riga (lo spazio non era misurato), altrimenti le 168 ore con zero
     *   dove non c'è niente.
     * - `days`: quanti giorni distinti hanno almeno una riga nella finestra.
     *   Sotto `sponsorship.forecast_min_days` la stima è «provvisoria».
     *
     * @return array{days: int, weeks: list<array<int, int>|null>}
     */
    public function weekly(City $city, SponsorshipPlacement|string $placement, CarbonInterface $now, int $weeks = 4, ?string $channel = null): array
    {
        $timezone = (string) $city->timezone;
        $end = CarbonImmutable::instance($now)->setTimezone($timezone)->startOfHour();
        $start = $end->subWeeks(max(1, $weeks));

        /* L'ora in orologio da muro, in un fuso senza cambi d'ora: il passaggio
           all'ora legale non deve spostare una riga da una settimana all'altra. */
        $endWall = CarbonImmutable::parse($end->format('Y-m-d H:00:00'), 'UTC');

        $rows = SponsorshipSlotHourly::query()
            ->where('city_id', $city->getKey())
            ->where('placement', self::placementValue($placement))
            ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
            ->whereBetween('day', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('day, hour, SUM(views) AS views')
            ->groupBy('day', 'hour')
            ->toBase()
            ->get();

        $series = array_fill(0, max(1, $weeks), null);
        $days = [];

        foreach ($rows as $row) {
            $day = is_string($row->day) ? substr($row->day, 0, 10) : null;
            $hour = is_numeric($row->hour) ? (int) $row->hour : null;

            if ($day === null || $hour === null || $hour < 0 || $hour > 23) {
                continue;
            }

            $wall = CarbonImmutable::parse($day.' '.sprintf('%02d', $hour).':00:00', 'UTC');
            $hoursBack = (int) $wall->diffInHours($endWall, true);

            /* Prima della finestra, oppure l'ora in corso o una futura. */
            if ($wall->greaterThanOrEqualTo($endWall) || $hoursBack < 1 || $hoursBack > count($series) * self::HOURS_PER_WEEK) {
                continue;
            }

            $week = intdiv($hoursBack - 1, self::HOURS_PER_WEEK);
            $series[$week] ??= array_fill(0, self::HOURS_PER_WEEK, 0);
            $series[$week][($wall->dayOfWeekIso - 1) * 24 + $hour] += is_numeric($row->views) ? (int) $row->views : 0;
            $days[$day] = true;
        }

        return ['days' => count($days), 'weeks' => array_values($series)];
    }

    /**
     * Il tasso di clic medio dello spazio negli ultimi `$days` giorni:
     * clic ÷ occasioni in cui c'era una sponsorizzazione. `null` se non ce n'è
     * stata nessuna: un rapporto su zero non è «zero per cento».
     */
    public function clickRate(City $city, SponsorshipPlacement|string $placement, CarbonInterface $now, int $days = 28): ?float
    {
        $today = CarbonImmutable::instance($now)->setTimezone((string) $city->timezone)->startOfDay();

        $totals = SponsorshipSlotHourly::query()
            ->where('city_id', $city->getKey())
            ->where('placement', self::placementValue($placement))
            ->where('day', '>', $today->subDays(max(1, $days))->toDateString())
            ->where('day', '<=', $today->toDateString())
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(sponsored_views), 0) AS sponsored_views')
            ->toBase()
            ->first();

        $viste = is_numeric($totals?->sponsored_views) ? (int) $totals->sponsored_views : 0;
        $clic = is_numeric($totals?->clicks) ? (int) $totals->clicks : 0;

        return $viste === 0 ? null : $clic / $viste;
    }

    private static function placementValue(SponsorshipPlacement|string $placement): string
    {
        return $placement instanceof SponsorshipPlacement ? $placement->value : $placement;
    }
}
