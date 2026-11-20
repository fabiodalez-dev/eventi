<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Models\City;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Services\Sponsorship\Delivery\Pacer;
use App\Services\Sponsorship\Delivery\PacingState;
use App\Services\Sponsorship\Delivery\TrafficForecast;
use App\Services\Sponsorship\Delivery\TrafficHistory;
use App\Services\Sponsorship\Delivery\UnitReach;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * L'avanzamento delle unità di consegna, per i pannelli e per i rapporti.
 *
 * Somma i numeri di un'unità (le righe di un'abilitazione, **comprese quelle
 * nel cestino**, come `SponsorshipGrant::deliveredImpressions()`), trova la
 * fine vera (`Pacer::trueEnd()`), legge la previsione del traffico dello
 * spazio (`TrafficHistory` e `TrafficForecast`) e ne ricava stato, atteso e
 * stima della data di arrivo (`UnitProgress`).
 *
 * **La stessa base del motore.** Fine vera e città vengono da `UnitReach`,
 * come in `DeliveryPlanBuilder`: per la card della home e il foglio della
 * mappa l'unità esce quando comincia l'ultima data, per le altre collocazioni
 * quando finisce; un'abilitazione con eventi in più città si giudica sulla
 * somma delle loro previsioni.
 *
 * **Poche letture per tante unità.** `grants()` e `campaigns()` ricevono un
 * elenco e fanno una lettura per i totali, una per le ultime date, una per le
 * città e una per ogni coppia città e collocazione: il numero di query non
 * cresce con le unità della pagina.
 *
 * Le occorrenze e le righe si leggono con il costruttore di query e non con i
 * modelli: nei pannelli di locali e organizzatori Filament restringe i modelli
 * al tenant, e una sotto-query su `Event` lo erediterebbe.
 *
 * **La stima della data** («al ritmo attuale arrivi all'obiettivo …») è una
 * proiezione lineare: la parte di traffico previsto che l'unità ha preso
 * finora, applicata al traffico previsto che resta. Si fa solo quando è
 * passato almeno mezzo giorno di traffico previsto: prima è rumore.
 */
final class SponsorshipProgress
{
    /** Quanto traffico previsto deve essere passato, in giorni, prima di stimare la data di arrivo. */
    private const MIN_DAYS_FOR_PROJECTION = 0.5;

    private const FALLBACK_TIMEZONE = 'Europe/Rome';

    /** @var array<string, TrafficForecast> */
    private array $forecasts = [];

    public function __construct(
        private readonly TrafficHistory $history,
        private readonly Pacer $pacer,
        private readonly UnitReach $reach,
    ) {}

    /** L'avanzamento dell'unità a cui appartiene una riga: la sua abilitazione, o lei stessa. */
    public function forRow(Sponsorship $row, ?CarbonInterface $now = null): UnitProgress
    {
        if ($row->sponsorship_grant_id !== null) {
            $grant = $row->relationLoaded('grant') ? $row->grant : SponsorshipGrant::query()->find($row->sponsorship_grant_id);

            if ($grant instanceof SponsorshipGrant) {
                return $this->forGrant($grant, $now);
            }
        }

        return $this->campaigns([$row], $now)[(int) $row->getKey()];
    }

    public function forGrant(SponsorshipGrant $grant, ?CarbonInterface $now = null): UnitProgress
    {
        return $this->grants([$grant], $now)[(int) $grant->getKey()];
    }

    /**
     * @param  iterable<SponsorshipGrant>  $grants
     * @return array<int, UnitProgress> per id dell'abilitazione
     */
    public function grants(iterable $grants, ?CarbonInterface $now = null): array
    {
        $grants = new EloquentCollection(is_array($grants) ? $grants : iterator_to_array($grants, false));

        if ($grants->isEmpty()) {
            return [];
        }

        $now = self::instant($now);
        $grants->loadMissing(['venue', 'organizer']);
        $ids = array_map('intval', $grants->modelKeys());

        /* Tutte le righe, anche quelle nel cestino: le visualizzazioni date restano date. */
        $totals = DB::table('sponsorships')
            ->whereIn('sponsorship_grant_id', $ids)
            ->groupBy('sponsorship_grant_id')
            ->selectRaw('sponsorship_grant_id AS unit, COALESCE(SUM(impressions), 0) AS impressions, COALESCE(SUM(clicks), 0) AS clicks')
            ->get()
            ->keyBy('unit');

        $reach = $this->reach->forGrants($ids, $now);
        $cityIds = $grants->map(fn (SponsorshipGrant $grant): ?int => $grant->sponsorCityId())->values()->all();

        foreach ($reach as $unit) {
            array_push($cityIds, ...$unit['cities']);
        }

        $cities = $this->cities($cityIds);

        $progress = [];

        foreach ($grants as $grant) {
            $id = (int) $grant->getKey();
            $row = $totals->get($id);
            $cityId = $grant->sponsorCityId();
            $unitCities = array_values(array_filter(array_map(static fn (int $city): ?City => $cities[$city] ?? null, $reach[$id]['cities'] ?? [])));
            $home = $cityId === null ? null : ($cities[$cityId] ?? null);

            $progress[$id] = $this->build(
                key: $grant->unitKey(),
                goal: $grant->impressions_goal,
                delivered: is_numeric($row->impressions ?? null) ? (int) $row->impressions : 0,
                clicks: is_numeric($row->clicks ?? null) ? (int) $row->clicks : 0,
                start: $grant->starts_at,
                end: $grant->ends_at,
                lastCovered: $reach[$id]['last'] ?? null,
                city: $home ?? ($unitCities[0] ?? null),
                placement: $grant->placement,
                pacing: $grant->pacing,
                halted: self::grantHalted($grant, $now),
                now: $now,
                forecast: count($unitCities) > 1 ? TrafficForecast::sum(array_map(fn (City $city): TrafficForecast => $this->forecast($city, $grant->placement, $now), $unitCities)) : null,
            );
        }

        return $progress;
    }

    /**
     * Le campagne singole: ogni riga è un'unità a sé. Una riga di
     * un'abilitazione passata qui vale come unità da sola: per l'unità vera
     * c'è `forRow()`.
     *
     * @param  iterable<Sponsorship>  $campaigns
     * @return array<int, UnitProgress> per id della campagna
     */
    public function campaigns(iterable $campaigns, ?CarbonInterface $now = null): array
    {
        $campaigns = new EloquentCollection(is_array($campaigns) ? $campaigns : iterator_to_array($campaigns, false));

        if ($campaigns->isEmpty()) {
            return [];
        }

        $now = self::instant($now);
        $ids = array_map('intval', $campaigns->modelKeys());
        $lastDates = $this->lastDates('sponsorships.id', fn (QueryBuilder $query) => $query->whereIn('sponsorships.id', $ids), activeOnly: false);
        $cities = $this->cities($campaigns->map(fn (Sponsorship $campaign): int => (int) $campaign->city_id)->all());

        $progress = [];

        foreach ($campaigns as $campaign) {
            $id = (int) $campaign->getKey();

            $progress[$id] = $this->build(
                key: $campaign->unitKey(),
                goal: $campaign->impressions_goal,
                delivered: (int) $campaign->impressions,
                clicks: (int) $campaign->clicks,
                start: $campaign->starts_at,
                end: $campaign->ends_at,
                lastCovered: isset($lastDates[$id]) ? UnitReach::lastCovered($campaign->placement, $lastDates[$id]['start'], $lastDates[$id]['end']) : null,
                city: $cities[(int) $campaign->city_id] ?? null,
                placement: $campaign->placement,
                pacing: $campaign->pacing,
                halted: $campaign->status === SponsorshipStatus::Active ? null : UnitProgress::HALTED_PAUSED,
                now: $now,
            );
        }

        return $progress;
    }

    /** La previsione del traffico dello spazio, una volta sola per città, collocazione e ora. */
    public function forecast(?City $city, SponsorshipPlacement|string $placement, CarbonInterface $now): TrafficForecast
    {
        $placement = $placement instanceof SponsorshipPlacement ? $placement->value : $placement;
        $key = ($city?->getKey() ?? 0).':'.$placement.':'.CarbonImmutable::instance($now)->utc()->format('YmdH');

        if (isset($this->forecasts[$key])) {
            return $this->forecasts[$key];
        }

        $default = config('sponsorship.default_daily_views.'.$placement, 0);
        $default = is_numeric($default) ? (float) $default : 0.0;

        if ($city === null) {
            return $this->forecasts[$key] = TrafficForecast::startingCurve($default);
        }

        $weights = config('sponsorship.forecast_weights', TrafficForecast::DEFAULT_WEIGHTS);
        $minDays = config('sponsorship.forecast_min_days', TrafficForecast::DEFAULT_MIN_DAYS);

        return $this->forecasts[$key] = TrafficForecast::fromHistory(
            $this->history->weekly($city, $placement, $now),
            $default,
            is_array($weights) ? array_values(array_map(static fn (mixed $w): float => is_numeric($w) ? (float) $w : 0.0, $weights)) : TrafficForecast::DEFAULT_WEIGHTS,
            is_numeric($minDays) ? (int) $minDays : TrafficForecast::DEFAULT_MIN_DAYS,
        );
    }

    private function build(
        string $key,
        ?int $goal,
        int $delivered,
        int $clicks,
        CarbonInterface $start,
        CarbonInterface $end,
        ?CarbonImmutable $lastCovered,
        ?City $city,
        SponsorshipPlacement $placement,
        ?SponsorshipPacing $pacing,
        ?string $halted,
        CarbonImmutable $now,
        ?TrafficForecast $forecast = null,
    ): UnitProgress {
        $timezone = $city !== null && $city->timezone !== '' ? $city->timezone : self::FALLBACK_TIMEZONE;
        $now = $now->setTimezone($timezone);
        $start = CarbonImmutable::instance($start)->setTimezone($timezone);
        $end = CarbonImmutable::instance($end)->setTimezone($timezone);

        /* L'ultima data tiene l'unità nella collocazione fino al suo inizio o
           alla sua fine, come nel piano (`UnitReach::lastCovered()`). */
        $lastCovered = $lastCovered?->setTimezone($timezone);
        $idle = $now->lessThan($end) && ($lastCovered === null || $lastCovered->lessThanOrEqualTo($now));
        $trueEnd = $idle || $lastCovered === null ? $end : Pacer::trueEnd($end, $lastCovered)->setTimezone($timezone);
        $trueEnd = $trueEnd->lessThan($start) ? $start : $trueEnd;

        $forecast ??= $this->forecast($city, $placement, $now);
        $pacing ??= SponsorshipPacing::Even;

        $state = null;
        $expected = 0.0;
        $quota = null;
        $projectedDate = null;
        $projectedTotal = null;

        if ($goal !== null) {
            $until = $now->lessThan($trueEnd) ? $now : $trueEnd;
            $elapsed = $now->greaterThan($start) ? $forecast->between($start, $until) : 0.0;
            $expected = PacingState::expected($goal, $elapsed, $forecast->between($start, $trueEnd));
            $state = PacingState::at($goal, $delivered, $forecast, $start, $trueEnd, $now, $pacing, self::thresholds(), $this->pacer->quotaMax());
            $quota = $this->pacer->quotaUntil($goal, $delivered, $forecast, $now->greaterThan($start) ? $now : $start, $trueEnd, $pacing);

            if ($halted === null && ! $idle && $delivered > 0 && $delivered < $goal && $now->lessThan($trueEnd)
                && $elapsed >= self::MIN_DAYS_FOR_PROJECTION * $forecast->dailyAverage() && $elapsed > 0) {
                [$projectedDate, $projectedTotal] = self::project($goal, $delivered, $elapsed, $forecast, $now, $trueEnd);
            }
        }

        return new UnitProgress(
            key: $key,
            goal: $goal,
            delivered: $delivered,
            clicks: $clicks,
            start: $start,
            end: $end,
            trueEnd: $trueEnd,
            now: $now,
            timezone: $timezone,
            state: $state,
            expected: $expected,
            quota: $quota,
            projectedDate: $projectedDate,
            projectedTotal: $projectedTotal,
            idle: $idle,
            halted: $halted,
            forecast: $forecast,
        );
    }

    /**
     * Al ritmo di finora: la data in cui si arriva all'obiettivo, se cade
     * prima della fine; altrimenti quante visualizzazioni ci saranno alla fine.
     *
     * @return array{0: CarbonImmutable|null, 1: int|null}
     */
    private static function project(int $goal, int $delivered, float $elapsed, TrafficForecast $forecast, CarbonImmutable $now, CarbonImmutable $end): array
    {
        $share = $delivered / $elapsed;
        $needed = ($goal - $delivered) / $share;
        $available = $forecast->between($now, $end);

        if ($needed > $available) {
            return [null, min($goal, $delivered + (int) round($share * $available))];
        }

        /* La prima ora in cui il traffico previsto da adesso basta: ricerca
           binaria sui secondi, `between()` costa poco. */
        $low = $now->getTimestamp();
        $high = $end->getTimestamp();

        while ($high - $low > 60) {
            $middle = intdiv($low + $high, 2);

            if ($forecast->between($now, $now->setTimestamp($middle)) >= $needed) {
                $high = $middle;
            } else {
                $low = $middle;
            }
        }

        return [$now->setTimestamp($high), null];
    }

    /**
     * Perché un'abilitazione nel suo periodo non consegna: revocata o con il
     * committente fermo, oppure in attesa del pagamento. Le stesse condizioni
     * di `SponsorshipGrant::scopeActive()`.
     */
    private static function grantHalted(SponsorshipGrant $grant, CarbonImmutable $now): ?string
    {
        if (! $grant->enabled) {
            return UnitProgress::HALTED_PAUSED;
        }

        if (! $grant->complimentary && ($grant->paid_at === null || $grant->paid_at->isAfter($now) || (int) $grant->amount_cents < 1)) {
            return UnitProgress::HALTED_UNPAID;
        }

        return $grant->sponsorIsOperational() ? null : UnitProgress::HALTED_PAUSED;
    }

    /**
     * Inizio e fine dell'ultima data valida degli eventi coperti, per unità.
     *
     * @param  callable(QueryBuilder): mixed  $scope
     * @return array<int, array{start: CarbonImmutable|null, end: CarbonImmutable}>
     */
    private function lastDates(string $unitColumn, callable $scope, bool $activeOnly = true): array
    {
        $query = DB::table('event_occurrences')
            ->join('sponsorships', 'sponsorships.event_id', '=', 'event_occurrences.event_id')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->whereNull('sponsorships.deleted_at')
            ->whereNull('event_occurrences.deleted_at')
            ->whereNull('events.deleted_at')
            ->where('events.status', EventStatus::Published->value)
            ->whereIn('event_occurrences.status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::SoldOut->value])
            ->when($activeOnly, fn (QueryBuilder $query) => $query->where('sponsorships.status', SponsorshipStatus::Active->value))
            ->groupBy($unitColumn)
            ->selectRaw($unitColumn.' AS unit, MAX(event_occurrences.starts_at) AS last_start, MAX(event_occurrences.effective_ends_at) AS last_end');

        $scope($query);

        $dates = [];

        foreach ($query->get() as $row) {
            if (is_numeric($row->unit) && is_string($row->last_end)) {
                $dates[(int) $row->unit] = [
                    'start' => is_string($row->last_start) ? CarbonImmutable::parse($row->last_start, 'UTC') : null,
                    'end' => CarbonImmutable::parse($row->last_end, 'UTC'),
                ];
            }
        }

        return $dates;
    }

    /**
     * @param  array<int|string, int|null>  $ids
     * @return array<int, City>
     */
    private function cities(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null)));

        if ($ids === []) {
            return [];
        }

        return City::query()->whereKey($ids)->get()->keyBy(fn (City $city): int => (int) $city->getKey())->all();
    }

    /** @return array{late: float, on_track: float, ahead: float} */
    private static function thresholds(): array
    {
        $configured = config('sponsorship.pacing_thresholds', []);
        $thresholds = PacingState::DEFAULT_THRESHOLDS;

        foreach (array_keys($thresholds) as $name) {
            $value = is_array($configured) ? ($configured[$name] ?? null) : null;

            if (is_numeric($value)) {
                $thresholds[$name] = (float) $value;
            }
        }

        return $thresholds;
    }

    private static function instant(?CarbonInterface $now): CarbonImmutable
    {
        return $now === null ? CarbonImmutable::now('UTC') : CarbonImmutable::instance($now);
    }
}
