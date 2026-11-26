<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Editorial;

use App\Enums\OccurrenceStatus;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipGrant;
use App\Services\Sponsorship\Delivery\EventWeights;
use App\Services\Sponsorship\Delivery\Pacer;
use App\Services\Sponsorship\Delivery\TrafficForecast;
use App\Services\Sponsorship\SponsorshipProgress as UnitProgressCalculator;
use App\Services\Sponsorship\UnitProgress as CalculatedProgress;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * L'avanzamento delle unità di consegna, per i pannelli della redazione.
 *
 * Il calcolo è uno solo per pannelli, rapporti e app
 * (`App\Services\Sponsorship\SponsorshipProgress`, D70): consegnate,
 * obiettivo, fine vera, atteso e stato vengono da lì. Qui si aggiunge solo ciò
 * che serve alla redazione: la quota richiesta senza tetto (per dire «oltre
 * l'80%»), le consegnate di oggi, i giorni rimasti, il giorno per giorno e gli
 * eventi di un'abilitazione.
 *
 * **Un'istanza per richiesta.** Le previsioni (una per città e collocazione)
 * e le unità già calcolate restano in memoria: in un elenco di venti righe di
 * una stessa abilitazione la si calcola una volta sola.
 */
final class SponsorshipProgress
{
    /** @var array<string, UnitProgress> */
    private array $units = [];

    public function __construct(
        private readonly UnitProgressCalculator $calculator,
        private readonly Pacer $pacer,
        private readonly EventWeights $weights,
    ) {}

    public function quotaMax(): float
    {
        return $this->pacer->quotaMax();
    }

    /**
     * L'avanzamento dell'unità a cui appartiene la riga: l'abilitazione per le
     * righe di un'abilitazione, la riga stessa per le campagne singole.
     */
    public function forSponsorship(Sponsorship $row, ?CarbonImmutable $now = null): ?UnitProgress
    {
        if ($row->sponsorship_grant_id !== null) {
            $grant = $row->relationLoaded('grant') ? $row->grant : $row->grant()->first();

            return $grant instanceof SponsorshipGrant ? $this->forGrant($grant, $now) : null;
        }

        $key = $row->unitKey();

        return $this->units[$key] ??= $this->adapt(
            $this->calculator->forRow($row, $now),
            $row->pacing ?? SponsorshipPacing::Even,
            [(int) $row->getKey()],
        );
    }

    public function forGrant(SponsorshipGrant $grant, ?CarbonImmutable $now = null): UnitProgress
    {
        $key = $grant->unitKey();

        if (isset($this->units[$key])) {
            return $this->units[$key];
        }

        /* Le righe nel cestino restano: le loro visualizzazioni di oggi sono date. */
        $ids = Sponsorship::withTrashed()
            ->where('sponsorship_grant_id', $grant->getKey())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $this->units[$key] = $this->adapt(
            $this->calculator->forGrant($grant, $now),
            $grant->pacing ?? SponsorshipPacing::Even,
            $ids,
        );
    }

    /**
     * La previsione oraria di uno spazio: dai dati misurati se ci sono,
     * altrimenti dalla curva di partenza sul valore di configurazione.
     */
    public function forecast(City $city, SponsorshipPlacement $placement, ?CarbonImmutable $now = null): TrafficForecast
    {
        return $this->calculator->forecast($city, $placement, $now ?? CarbonImmutable::now('UTC'));
    }

    /**
     * Giorno per giorno: consegnate contro attese, dall'inizio a oggi (al
     * massimo gli ultimi `$days` giorni), nel fuso della città.
     *
     * @param  list<int>  $sponsorshipIds
     * @return list<array{day: CarbonImmutable, delivered: int, expected: int}>
     */
    public function daily(UnitProgress $progress, array $sponsorshipIds, TrafficForecast $forecast, ?CarbonImmutable $now = null, int $days = 14): array
    {
        if ($sponsorshipIds === []) {
            return [];
        }

        $now = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($progress->timezone);
        $start = $progress->start->setTimezone($progress->timezone);
        $end = $progress->end->setTimezone($progress->timezone);
        $last = ($now->lessThan($end) ? $now : $end)->startOfDay();
        $first = $start->startOfDay();

        if ($last->lessThan($first)) {
            return [];
        }

        if ((int) $first->diffInDays($last) >= $days) {
            $first = $last->subDays($days - 1);
        }

        $delivered = SponsorshipDailyStat::query()
            ->whereIn('sponsorship_id', $sponsorshipIds)
            ->whereBetween('day', [$first->toDateString(), $last->toDateString()])
            ->selectRaw('day, SUM(impressions) AS views')
            ->groupBy('day')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [substr((string) $row->day, 0, 10) => (int) $row->views])
            ->all();

        $total = $forecast->between($start, $end);
        $rows = [];

        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $from = $day->greaterThan($start) ? $day : $start;
            $to = $day->addDay()->lessThan($end) ? $day->addDay() : $end;
            /* Il giorno in corso si confronta con l'atteso fino a adesso, non
               con quello di tutta la giornata. */
            $to = $to->greaterThan($now) ? $now : $to;
            $expected = $progress->goal !== null && $total > 0
                ? (int) round($progress->goal * $forecast->between($from, $to) / $total)
                : 0;

            $rows[] = [
                'day' => $day,
                'delivered' => $delivered[$day->toDateString()] ?? 0,
                'expected' => $expected,
            ];
        }

        return $rows;
    }

    /**
     * Gli eventi di un'abilitazione con i loro numeri e la quota che hanno
     * oggi dentro l'unità (vicinanza della data, `EventWeights`).
     *
     * @return list<array{row: Sponsorship, event: Event|null, impressions: int, clicks: int, share: float|null, next: CarbonImmutable|null}>
     */
    public function grantEvents(SponsorshipGrant $grant, ?CarbonImmutable $now = null, int $limit = 50): array
    {
        $now ??= CarbonImmutable::now('UTC');

        $rows = Sponsorship::query()
            ->where('sponsorship_grant_id', $grant->getKey())
            ->with(['event.media', 'event.venue:id,name', 'event.city:id,timezone'])
            ->orderByDesc('impressions')
            ->limit($limit)
            ->get();

        $next = $this->nextDates($rows->pluck('event_id')->map(fn ($id): int => (int) $id)->all(), $now);
        $shares = $this->weights->shares($next, $now);

        /* Prima chi ha dato di più; a pari numeri, la data più vicina. */
        $rows = $rows->sortBy([
            fn (Sponsorship $a, Sponsorship $b): int => (int) $b->impressions <=> (int) $a->impressions,
            fn (Sponsorship $a, Sponsorship $b): int => ($next[(int) $a->event_id]?->getTimestamp() ?? PHP_INT_MAX) <=> ($next[(int) $b->event_id]?->getTimestamp() ?? PHP_INT_MAX),
        ]);

        return $rows->map(fn (Sponsorship $row): array => [
            'row' => $row,
            'event' => $row->event,
            'impressions' => (int) $row->impressions,
            'clicks' => (int) $row->clicks,
            'share' => $shares[(int) $row->event_id] ?? null,
            'next' => $next[(int) $row->event_id] ?? null,
        ])->values()->all();
    }

    /**
     * Gli id delle righe `sponsorships` le cui unità sono nello stato chiesto
     * (`period` per le unità a periodo). Serve al filtro dell'elenco: lo stato
     * è calcolato, non salvato, quindi non si può chiedere al database.
     *
     * @return list<int>
     */
    public function sponsorshipIdsInState(string $state, ?CarbonImmutable $now = null): array
    {
        $ids = [];

        if ($state === 'period') {
            return Sponsorship::query()
                ->whereNull('impressions_goal')
                ->where(fn (Builder $q) => $q->whereNull('sponsorship_grant_id')
                    ->orWhereIn('sponsorship_grant_id', SponsorshipGrant::query()->whereNull('impressions_goal')->select('id')))
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        $singles = Sponsorship::query()
            ->whereNull('sponsorship_grant_id')
            ->whereNotNull('impressions_goal')
            ->with('city')
            ->get();

        foreach ($singles as $row) {
            if ($this->forSponsorship($row, $now)?->stateKey() === $state) {
                $ids[] = (int) $row->getKey();
            }
        }

        $grants = SponsorshipGrant::query()->whereNotNull('impressions_goal')->with(['venue', 'organizer'])->get();

        foreach ($grants as $grant) {
            if ($this->forGrant($grant, $now)->stateKey() === $state) {
                array_push($ids, ...$grant->sponsorships()->pluck('id')->map(fn ($id): int => (int) $id)->all());
            }
        }

        return $ids;
    }

    /**
     * Dal calcolo comune alla scheda della redazione.
     *
     * @param  list<int>  $sponsorshipIds
     */
    private function adapt(CalculatedProgress $unit, SponsorshipPacing $pacing, array $sponsorshipIds): UnitProgress
    {
        $now = $unit->now;
        $end = $unit->trueEnd;
        $daysLeft = $now->greaterThanOrEqualTo($end) ? 0 : (int) ceil($now->diffInSeconds($end) / 86400);
        $today = $this->deliveredOn($sponsorshipIds, $now->toDateString());

        if ($unit->goal === null) {
            return new UnitProgress($unit->key, null, $unit->delivered, null, 0.0, null, $today, $daysLeft, $unit->start, $end, $unit->timezone);
        }

        /* Senza tetto, per poter dire «oltre l'80%» quando il traffico che
           resta non basta: la stessa formula del motore. */
        $required = $now->greaterThanOrEqualTo($end)
            ? 0.0
            : $this->pacer->requiredUntil($unit->goal, $unit->delivered, $unit->forecast, $now->greaterThan($unit->start) ? $now : $unit->start, $end, $pacing);

        return new UnitProgress($unit->key, $unit->goal, $unit->delivered, $unit->state, $unit->expected, $required, $today, $daysLeft, $unit->start, $end, $unit->timezone);
    }

    /** @param  list<int>  $sponsorshipIds */
    private function deliveredOn(array $sponsorshipIds, string $day): int
    {
        if ($sponsorshipIds === []) {
            return 0;
        }

        return (int) SponsorshipDailyStat::query()
            ->whereIn('sponsorship_id', $sponsorshipIds)
            ->where('day', $day)
            ->sum('impressions');
    }

    /**
     * La prossima data valida di ogni evento (o adesso, se una è in corso).
     *
     * @param  list<int>  $eventIds
     * @return array<int, CarbonImmutable|null>
     */
    private function nextDates(array $eventIds, CarbonImmutable $now): array
    {
        if ($eventIds === []) {
            return [];
        }

        $next = EventOccurrence::query()
            ->whereIn('event_id', $eventIds)
            ->whereIn('status', [OccurrenceStatus::Scheduled, OccurrenceStatus::SoldOut])
            ->where('effective_ends_at', '>', $now)
            ->groupBy('event_id')
            ->selectRaw('event_id, MIN(starts_at) AS next_at')
            ->toBase()
            ->get()
            ->mapWithKeys(function (object $row) use ($now): array {
                $at = CarbonImmutable::parse((string) $row->next_at, 'UTC');

                return [(int) $row->event_id => $at->lessThan($now) ? $now : $at];
            })
            ->all();

        $dates = [];

        foreach ($eventIds as $id) {
            $dates[$id] = $next[$id] ?? null;
        }

        return $dates;
    }
}
