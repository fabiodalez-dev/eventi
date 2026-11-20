<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use App\Enums\OccurrenceStatus;
use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\Sponsorship;
use App\Queries\EventOccurrenceQuery;
use App\Support\ContentVersion;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Costruisce il piano di consegna di una città e di una collocazione, e lo
 * tiene in cache.
 *
 * **Chi può comparire** lo decide `Sponsorship::visible()`, come sempre, più una
 * condizione: almeno un evento dell'unità con una data ancora valida. **Con
 * quale quota** lo decidono le formule pure: `Pacer` per le unità a
 * visualizzazioni, `QuotaAllocator` per fasce, pesi e riserva, `EventWeights`
 * per gli eventi dentro l'unità.
 *
 * Il piano si ricostruisce ogni minuto dal comando `sponsorships:plan`. Se il
 * cron è fermo o il piano manca, chi serve la pagina lo ricostruisce al volo
 * con un lock: è una query in più ogni tanto, non un guasto. La chiave porta
 * dentro la versione dei contenuti della città, quindi salvare una campagna o
 * un'abilitazione lo fa ricostruire subito.
 *
 * Il banner ha un piano suo (`BANNER`): pesca fra tutte le collocazioni, un
 * evento una volta sola, e ogni unità vi concorre a periodo con il suo peso,
 * perché il traffico del banner non è quello di nessuna collocazione. **Le
 * unità a visualizzazioni restano fuori dal banner**: le sue visualizzazioni
 * finiscono nello stesso contatore dell'obiettivo, e passando di lì senza
 * ritmo lo consumerebbero prima del tempo, contro la consegna distribuita che
 * il committente ha comprato.
 *
 * **Un'unità in più città** (un organizzatore con eventi in due città) ha un
 * obiettivo solo per tutte: la previsione è la somma di quelle delle città
 * in cui l'unità compare, non quella della città del piano, altrimenti ogni
 * città chiederebbe da sola tutto il mancante e l'unità andrebbe al doppio
 * del ritmo. `UnitReach` dà città e fine vera, gli stessi che usa
 * `SponsorshipProgress` per i pannelli.
 */
final class DeliveryPlanBuilder
{
    /** Il «posto» del banner, che non è una collocazione dell'enum. */
    public const BANNER = 'banner';

    /** Oltre questa quota richiesta il piano non scrive numeri più grandi: è già «a rischio». */
    private const QUOTA_CEILING = 1000.0;

    /** Il piano resta in cache più a lungo della sua validità: serve da ripiego mentre un altro lo ricostruisce. */
    private const STORE_MINUTES = 15;

    public function __construct(
        private readonly Pacer $pacer,
        private readonly EventWeights $eventWeights,
        private readonly TrafficHistory $history,
        private readonly EventWeightAdjuster $adjuster,
        private readonly UnitReach $reach,
        #[Config('sponsorship.plan_ttl_seconds', 120)] private readonly int $ttlSeconds = 120,
    ) {}

    public static function cacheKey(int $cityId, SponsorshipPlacement|string $placement): string
    {
        return 'sponsorizzazioni:piano:'.$cityId.':'.self::placementValue($placement).':'.ContentVersion::for($cityId);
    }

    /**
     * I posti per cui il comando costruisce un piano: le quattro collocazioni e il banner.
     *
     * @return list<string>
     */
    public static function places(): array
    {
        return [...SponsorshipPlacement::values(), self::BANNER];
    }

    /**
     * Il piano buono adesso: quello in cache se è abbastanza giovane,
     * altrimenti uno nuovo. Dalla cache non fa nessuna query.
     */
    public function current(City|int $city, SponsorshipPlacement|string $placement, ?CarbonImmutable $now = null): DeliveryPlan
    {
        $now ??= CarbonImmutable::now('UTC');
        $cityId = $city instanceof City ? (int) $city->getKey() : $city;
        $value = self::placementValue($placement);
        $key = self::cacheKey($cityId, $value);

        $cached = DeliveryPlan::fromArray(Cache::get($key));

        if ($cached !== null && $cached->isFresh($now->getTimestamp(), $this->ttlSeconds)) {
            return $cached;
        }

        $model = $city instanceof City ? $city : City::query()->find($cityId);

        if (! $model instanceof City) {
            return DeliveryPlan::empty($cityId, $value, $now->getTimestamp(), $this->pacer->quotaMax());
        }

        /* Uno solo ricostruisce; gli altri, nel frattempo, usano il piano di
           prima se c'è. Senza un piano di prima lo costruiscono anche loro,
           senza salvarlo: meglio una query in più che una pagina senza
           sponsorizzazione per un lock. */
        $lock = Cache::lock($key.':lock', 10);

        if (! $lock->get()) {
            return $cached ?? $this->build($model, $value, $now);
        }

        try {
            return $this->refresh($model, $value, $now);
        } finally {
            $lock->release();
        }
    }

    /** Costruisce il piano e lo mette in cache. */
    public function refresh(City $city, SponsorshipPlacement|string $placement, ?CarbonImmutable $now = null): DeliveryPlan
    {
        $plan = $this->build($city, $placement, $now);

        try {
            Cache::put(self::cacheKey((int) $city->getKey(), $placement), $plan->toArray(), now()->addMinutes(self::STORE_MINUTES));
        } catch (Throwable $exception) {
            report($exception);
        }

        return $plan;
    }

    /** Il piano di adesso, letto dal database. Non tocca la cache. */
    public function build(City $city, SponsorshipPlacement|string $placement, ?CarbonImmutable $now = null): DeliveryPlan
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $value = self::placementValue($placement);
        $banner = $value === self::BANNER;
        $cityId = (int) $city->getKey();

        /** @var Collection<int, Sponsorship> $rows */
        $rows = Sponsorship::query()
            ->visible($now)
            ->where('sponsorships.city_id', $cityId)
            ->when(! $banner, fn ($query) => $query->where('sponsorships.placement', $value))
            ->with(['grant', 'event'])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        if ($banner) {
            /* Fuori le unità a visualizzazioni, prima di togliere i doppioni:
               un evento sponsorizzato anche a periodo resta nel banner. Poi un
               evento compare una volta sola, anche se è sponsorizzato in due
               collocazioni: vince la riga davanti. */
            $rows = $rows->filter(fn (Sponsorship $row): bool => ($row->sponsorship_grant_id !== null ? $row->grant?->impressions_goal : $row->impressions_goal) === null)
                ->unique('event_id')->values();
        }

        if ($rows->isEmpty()) {
            return DeliveryPlan::empty($cityId, $value, $now->getTimestamp(), $this->pacer->quotaMax());
        }

        $dates = $this->dates($city, $rows->pluck('event_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(), $now);

        /* Quale data conta per ogni posto. La card e il foglio della mappa
           mostrano la prossima data che deve ancora cominciare: un evento in
           corso lì non avrebbe niente da mostrare. L'apertura e la cima
           dell'elenco mostrano anche la data in corso, come oggi. Il banner
           segue `visible()` fino alla mezzanotte locale, come oggi: un evento
           finito alle diciotto resta nel banner fino a fine giornata. */
        $field = match ($value) {
            SponsorshipPlacement::HomeCard->value, SponsorshipPlacement::MapSheet->value => 'future',
            self::BANNER => 'today',
            default => 'first',
        };

        /** @var array<string, list<Sponsorship>> $byUnit */
        $byUnit = [];
        /** @var array<int, CarbonImmutable> $next */
        $next = [];

        foreach ($rows as $row) {
            $date = $dates[(int) $row->event_id] ?? null;
            $nextDate = $date === null ? null : ($field === 'today' ? ($date['first'] ?? $now) : $date[$field]);

            if ($nextDate === null || $row->event === null) {
                continue;
            }

            $next[(int) $row->getKey()] = $nextDate->lessThan($now) ? $now : $nextDate;
            $byUnit[$row->unitKey()][] = $row;
        }

        if ($byUnit === []) {
            return DeliveryPlan::empty($cityId, $value, $now->getTimestamp(), $this->pacer->quotaMax());
        }

        $delivered = $this->delivered($byUnit);
        /** @var array<int, TrafficForecast> $forecasts per città */
        $forecasts = [];
        $reach = null;
        $units = [];

        foreach ($byUnit as $key => $unitRows) {
            $first = $unitRows[0];
            $grant = $first->sponsorship_grant_id !== null ? $first->grant : null;

            $goal = $grant !== null ? $grant->impressions_goal : $first->impressions_goal;
            $quota = null;

            if ($goal !== null) {
                $endsAt = $grant !== null ? $grant->ends_at : $first->ends_at;
                $pacing = $grant !== null ? $grant->pacing : $first->pacing;
                $lastCovered = null;
                $cityIds = [$cityId];

                foreach ($unitRows as $row) {
                    $date = $dates[(int) $row->event_id] ?? null;
                    $end = $date === null ? null : UnitReach::lastCovered($value, $date['last_start'], $date['last']);
                    $lastCovered = $end !== null && ($lastCovered === null || $end->greaterThan($lastCovered)) ? $end : $lastCovered;
                }

                /* Un'abilitazione può avere righe in più città: una previsione
                   sola, la somma, e la fine vera fra tutte. */
                if ($grant !== null) {
                    $reach ??= $this->reach->forGrants($this->goalGrantIds($byUnit), $now);
                    $grantReach = $reach[(int) $grant->getKey()] ?? null;

                    if ($grantReach !== null && $grantReach['cities'] !== []) {
                        $cityIds = $grantReach['cities'];
                        $lastCovered = $grantReach['last'] ?? $lastCovered;
                    }
                }

                $local = $now->setTimezone($city->timezone);
                $required = $this->pacer->requiredUntil(
                    (int) $goal,
                    $grant !== null ? ($delivered[$key] ?? 0) : (int) $first->impressions,
                    $this->unitForecast($city, $value, $cityIds, $now, $forecasts),
                    $local,
                    Pacer::trueEnd($endsAt, $lastCovered)->setTimezone($city->timezone),
                    $pacing,
                );
                $quota = is_finite($required) ? min($required, self::QUOTA_CEILING) : self::QUOTA_CEILING;
            }

            $weights = $this->eventWeights->weights(
                array_combine(
                    array_map(static fn (Sponsorship $row): int => (int) $row->getKey(), $unitRows),
                    array_map(fn (Sponsorship $row): CarbonImmutable => $next[(int) $row->getKey()], $unitRows),
                ),
                $now,
            );
            $weights = $this->adjuster->adjust($key, $weights, $now);

            $units[$key] = [
                'key' => $key,
                'priority' => (int) ($grant !== null ? $grant->priority : $first->priority),
                'weight' => max(1, (int) ($grant !== null ? $grant->weight : $first->weight)),
                'quota' => $quota,
                'rows' => array_map(static fn (Sponsorship $row): array => [
                    'id' => (int) $row->getKey(),
                    'event' => (int) $row->event_id,
                    'slug' => (string) $row->event?->slug,
                    'venue' => $row->event?->venue_id === null ? null : (int) $row->event->venue_id,
                    'category' => $row->event?->category_id === null ? null : (int) $row->event->category_id,
                    'weight' => (float) ($weights[(int) $row->getKey()] ?? 0.0),
                ], $unitRows),
            ];
        }

        return new DeliveryPlan($cityId, $value, $now->getTimestamp(), $units, $this->pacer->quotaMax());
    }

    /**
     * Per ogni evento con una data valida da oggi in poi (la mezzanotte della
     * città, come `visible()`): la prima data non ancora finita (anche in
     * corso), la prima che deve ancora cominciare, la fine dell'ultima e il
     * suo inizio.
     * Una query sola, aggregata, sulle date pubbliche della città senza le
     * preferenze di chi guarda: il piano è lo stesso per tutti.
     *
     * @param  list<int>  $eventIds
     * @return array<int, array{first: CarbonImmutable|null, future: CarbonImmutable|null, last: CarbonImmutable, last_start: CarbonImmutable|null}>
     */
    private function dates(City $city, array $eventIds, CarbonImmutable $now): array
    {
        $moment = $now->format('Y-m-d H:i:s');
        $midnight = $now->setTimezone($city->timezone)->startOfDay()->utc()->format('Y-m-d H:i:s');

        $aggregates = EventOccurrenceQuery::competitionFor($city)
            ->forEvents($eventIds)
            ->identifiersQuery()
            ->select('event_occurrences.event_id')
            ->selectRaw('MIN(CASE WHEN event_occurrences.effective_ends_at > ? THEN event_occurrences.starts_at END) as first_start', [$moment])
            ->selectRaw('MIN(CASE WHEN event_occurrences.starts_at > ? THEN event_occurrences.starts_at END) as next_future', [$moment])
            ->selectRaw('MAX(event_occurrences.effective_ends_at) as last_end')
            ->selectRaw('MAX(event_occurrences.starts_at) as last_start')
            ->where('event_occurrences.effective_ends_at', '>', $midnight)
            ->whereIn('event_occurrences.status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::SoldOut->value])
            ->groupBy('event_occurrences.event_id')
            ->toBase()
            ->get();

        $dates = [];

        foreach ($aggregates as $aggregate) {
            if (! is_string($aggregate->last_end ?? null)) {
                continue;
            }

            $dates[(int) $aggregate->event_id] = [
                'first' => is_string($aggregate->first_start ?? null) ? CarbonImmutable::parse($aggregate->first_start, 'UTC') : null,
                'future' => is_string($aggregate->next_future ?? null) ? CarbonImmutable::parse($aggregate->next_future, 'UTC') : null,
                'last' => CarbonImmutable::parse($aggregate->last_end, 'UTC'),
                'last_start' => is_string($aggregate->last_start ?? null) ? CarbonImmutable::parse($aggregate->last_start, 'UTC') : null,
            ];
        }

        return $dates;
    }

    /**
     * Le consegnate delle abilitazioni: la somma delle loro righe, comprese
     * quelle nel cestino (`SponsorshipGrant::deliveredImpressions()`), in una
     * query sola invece di una per abilitazione.
     *
     * @param  array<string, list<Sponsorship>>  $byUnit
     * @return array<string, int>
     */
    private function delivered(array $byUnit): array
    {
        $grantIds = $this->goalGrantIds($byUnit);

        if ($grantIds === []) {
            return [];
        }

        return Sponsorship::withTrashed()
            ->whereIn('sponsorship_grant_id', $grantIds)
            ->groupBy('sponsorship_grant_id')
            ->selectRaw('sponsorship_grant_id, COALESCE(SUM(impressions), 0) as delivered')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $sum): array => [self::grantKey((int) $sum->sponsorship_grant_id) => (int) $sum->delivered])
            ->all();
    }

    /**
     * Le abilitazioni del piano con un obiettivo di visualizzazioni.
     *
     * @param  array<string, list<Sponsorship>>  $byUnit
     * @return list<int>
     */
    private function goalGrantIds(array $byUnit): array
    {
        $grantIds = [];

        foreach ($byUnit as $unitRows) {
            if ($unitRows[0]->sponsorship_grant_id !== null && $unitRows[0]->grant?->impressions_goal !== null) {
                $grantIds[] = (int) $unitRows[0]->sponsorship_grant_id;
            }
        }

        return $grantIds;
    }

    /**
     * La previsione su cui spalmare l'obiettivo di un'unità: quella della
     * città del piano, o la somma di quelle delle città in cui l'unità
     * compare. Una lettura per città, una volta sola per piano.
     *
     * @param  list<int>  $cityIds
     * @param  array<int, TrafficForecast>  $forecasts
     */
    private function unitForecast(City $city, string $placement, array $cityIds, CarbonImmutable $now, array &$forecasts): TrafficForecast
    {
        $missing = array_values(array_diff($cityIds, array_keys($forecasts), [(int) $city->getKey()]));

        if (! isset($forecasts[(int) $city->getKey()]) && in_array((int) $city->getKey(), $cityIds, true)) {
            $forecasts[(int) $city->getKey()] = $this->forecast($city, $placement, $now);
        }

        if ($missing !== []) {
            foreach (City::query()->whereKey($missing)->get() as $other) {
                $forecasts[(int) $other->getKey()] = $this->forecast($other, $placement, $now);
            }
        }

        $parts = array_values(array_filter(array_map(static fn (int $id): ?TrafficForecast => $forecasts[$id] ?? null, $cityIds)));

        return $parts === [] ? $this->forecast($city, $placement, $now) : TrafficForecast::sum($parts);
    }

    /** La stessa chiave di `SponsorshipGrant::unitKey()`, senza caricare il modello. */
    private static function grantKey(int $grantId): string
    {
        return 'g:'.$grantId;
    }

    /** La previsione del traffico della collocazione, solo se qualcuno ha un obiettivo. */
    private function forecast(City $city, string $placement, CarbonImmutable $now): TrafficForecast
    {
        $default = config('sponsorship.default_daily_views.'.$placement, 0);
        $weights = config('sponsorship.forecast_weights', TrafficForecast::DEFAULT_WEIGHTS);
        $minDays = config('sponsorship.forecast_min_days', TrafficForecast::DEFAULT_MIN_DAYS);

        return TrafficForecast::fromHistory(
            $this->history->weekly($city, $placement, $now),
            is_numeric($default) ? (float) $default : 0.0,
            is_array($weights) ? array_values(array_map(static fn (mixed $weight): float => is_numeric($weight) ? (float) $weight : 0.0, $weights)) : TrafficForecast::DEFAULT_WEIGHTS,
            is_numeric($minDays) ? (int) $minDays : TrafficForecast::DEFAULT_MIN_DAYS,
        );
    }

    private static function placementValue(SponsorshipPlacement|string $placement): string
    {
        $value = $placement instanceof SponsorshipPlacement ? $placement->value : $placement;

        if ($value !== self::BANNER && SponsorshipPlacement::tryFrom($value) === null) {
            throw new InvalidArgumentException('Unknown sponsorship placement: '.$value);
        }

        return $value;
    }
}
