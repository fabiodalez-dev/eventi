<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Le statistiche delle sponsorizzazioni, scritte una volta per la pagina
 * «Statistiche sponsorizzazioni» dei pannelli
 * (`App\Filament\Shared\SponsorshipAnalyticsPage`) e per l'API dell'app.
 *
 * Quali campagne si vedono lo decide `SponsorshipReport` (il soggetto di
 * `ManagementSubject`). Qui stanno il periodo (sette giorni, trenta, novanta,
 * un anno, tutto lo storico o fra due date, nei giorni della città), la serie
 * giorno per giorno, il registro dei clic e, con una campagna scelta,
 * l'avanzamento della sua unità di consegna, le consegnate contro le attese e
 * i conti economici. Le date escono come istanti: le scrive chi le mostra.
 */
final class SponsorshipAnalyticsReport
{
    /** Il periodo libero più lungo che si disegna giorno per giorno. */
    public const MAX_DAYS = 731;

    /** Le righe del registro dei clic per pagina. */
    public const CLICKS_PER_PAGE = 25;

    /** Le campagne proposte nella scelta del filtro. */
    public const OPTIONS_LIMIT = 50;

    /** I periodi della tendina: giorni, tutto lo storico, fra due date. */
    public const PERIODS = ['7', '30', '90', '365', 'all', 'custom'];

    public function __construct(
        private readonly SponsorshipReport $campaigns,
        private readonly SponsorshipProgress $progress,
        private readonly CampaignEconomics $economics,
    ) {}

    public function timezone(): string
    {
        return $this->campaigns->timezone();
    }

    /**
     * Le campagne da scegliere nel filtro: le ultime, cercando nel titolo
     * dell'evento o nel nome del locale.
     *
     * @return Collection<int, Sponsorship>
     */
    public function campaignOptions(string $search = ''): Collection
    {
        $search = trim($search);

        return $this->campaigns->campaigns()->with('event.venue', 'event.city', 'event.media')
            ->when($search !== '', fn ($q) => $q->whereHas('event', fn ($e) => $e->where(fn ($q) => $q->where('title', 'like', $like = '%'.addcslashes($search, '%_\\').'%')->orWhereHas('venue', fn ($v) => $v->where('name', 'like', $like)))))
            ->latest('id')->limit(self::OPTIONS_LIMIT)->get();
    }

    /** Una campagna che chi guarda può vedere; `null` altrimenti. */
    public function campaign(int $id): ?Sponsorship
    {
        return $this->campaigns->campaigns()->with('event.venue', 'event.city', 'event.media')->find($id);
    }

    /**
     * Il report del periodo. `$filters`: `days` (uno di `PERIODS`), `from` e
     * `to` per «fra due date», `campaign` per una campagna sola. `$page` è la
     * pagina del registro dei clic; nullo, la decide chi pagina.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     economics: array<string, mixed>|null,
     *     progress: UnitProgress|null,
     *     selected: Sponsorship|null,
     *     grant_unit: bool,
     *     delivery: array{days: list<array{day: CarbonImmutable, delivered: int, expected: int}>, delivered: int, expected: int}|null,
     *     min_ctr: int,
     *     series: list<array{day: CarbonImmutable, impressions: int, clicks: int}>,
     *     impressions: int,
     *     clicks: int,
     *     from: CarbonImmutable,
     *     to: CarbonImmutable,
     *     records: LengthAwarePaginator<int, SponsorshipClick>
     * }
     */
    public function report(array $filters, ?int $page = null): array
    {
        $campaigns = $this->campaigns->campaigns();
        if (filled($filters['campaign'] ?? null)) {
            // Never accept an ID belonging to another venue, even in a forged request.
            abort_unless((clone $campaigns)->whereKey($filters['campaign'])->exists(), 403);
            $campaigns->whereKey($filters['campaign']);
        }
        $ids = (clone $campaigns)->select('sponsorships.id');
        [$from, $to] = $this->window(clone $ids, $filters);
        $daily = SponsorshipDailyStat::whereIn('sponsorship_id', clone $ids)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('day, SUM(impressions) as impressions, SUM(clicks) as clicks')->groupBy('day')->orderBy('day')->get()->keyBy(fn ($row) => $row->day->toDateString());
        $series = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $row = $daily->get($day->toDateString());
            $series[] = ['day' => $day, 'impressions' => (int) $row?->impressions, 'clicks' => (int) $row?->clicks];
        }

        $selected = filled($filters['campaign'] ?? null) ? (clone $campaigns)->firstOrFail() : null;
        $progress = $selected === null ? null : $this->progress->forRow($selected);

        return [
            'economics' => $selected === null ? null : $this->economics->for($selected),
            'progress' => $progress,
            'selected' => $selected,
            'grant_unit' => $selected?->sponsorship_grant_id !== null,
            'delivery' => $selected !== null && $progress !== null && $progress->hasGoal() ? $this->delivery($progress, $selected) : null,
            'min_ctr' => self::minImpressionsForRate(),
            'series' => $series,
            'impressions' => array_sum(array_column($series, 'impressions')),
            'clicks' => array_sum(array_column($series, 'clicks')),
            'from' => $from, 'to' => $to,
            'records' => SponsorshipClick::whereIn('sponsorship_id', clone $ids)
                ->where('clicked_at', '>=', $from->utc())->where('clicked_at', '<', $to->addDay()->utc())
                ->with(['sponsorship.event.venue', 'sponsorship.event.media'])->orderByDesc('clicked_at')->orderByDesc('id')
                ->paginate(self::CLICKS_PER_PAGE, ['*'], 'page', $page),
        ];
    }

    /** Sotto queste visualizzazioni il tasso di aperture non si mostra. */
    public static function minImpressionsForRate(): int
    {
        $min = config('sponsorship.min_impressions_for_ctr', 100);

        return is_numeric($min) ? max(1, (int) $min) : 100;
    }

    /**
     * Il primo e l'ultimo giorno, nel fuso della città.
     *
     * @param  Builder<Sponsorship>  $ids
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(Builder $ids, array $filters): array
    {
        $tz = $this->timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $period = (string) ($filters['days'] ?? '30');

        [$from, $to] = match ($period) {
            '7', '90', '365' => [$today->subDays((int) $period - 1), $today],
            'all' => [($first = SponsorshipDailyStat::whereIn('sponsorship_id', $ids)->min('day')) !== null
                ? CarbonImmutable::parse((string) $first, $tz)->startOfDay() : $today->subDays(29), $today],
            'custom' => [$this->dateFilter($filters['from'] ?? null, $today->subDays(29)), $this->dateFilter($filters['to'] ?? null, $today)],
            default => [$today->subDays(29), $today],
        };

        if ($to->greaterThan($today)) {
            $to = $today;
        }
        if ($from->greaterThan($to)) {
            $from = $to;
        }
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        return [$from, $to];
    }

    private function dateFilter(mixed $value, CarbonImmutable $fallback): CarbonImmutable
    {
        try {
            return filled($value) ? CarbonImmutable::parse((string) $value, $this->timezone())->startOfDay() : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * Le visualizzazioni consegnate dall'unità giorno per giorno, cumulate,
     * accanto a quelle attese seguendo il traffico previsto. Dall'inizio del
     * periodo a oggi (o alla fine vera), nei giorni della città.
     *
     * @return array{days: list<array{day: CarbonImmutable, delivered: int, expected: int}>, delivered: int, expected: int}
     */
    public function delivery(UnitProgress $progress, Sponsorship $row): array
    {
        $tz = $progress->timezone;
        $first = $progress->start->setTimezone($tz)->startOfDay();
        $last = ($progress->now->lessThan($progress->trueEnd) ? $progress->now : $progress->trueEnd)->setTimezone($tz)->startOfDay();

        if ($last->lessThan($first)) {
            return ['days' => [], 'delivered' => 0, 'expected' => 0];
        }
        if ($first->diffInDays($last) >= self::MAX_DAYS) {
            $first = $last->subDays(self::MAX_DAYS - 1);
        }

        $rows = $row->sponsorship_grant_id !== null
            ? Sponsorship::withTrashed()->where('sponsorship_grant_id', $row->sponsorship_grant_id)->select('id')
            : Sponsorship::withTrashed()->whereKey($row->getKey())->select('id');
        $before = (int) SponsorshipDailyStat::whereIn('sponsorship_id', clone $rows)->where('day', '<', $first->toDateString())->sum('impressions');
        $daily = SponsorshipDailyStat::whereIn('sponsorship_id', $rows)
            ->whereBetween('day', [$first->toDateString(), $last->toDateString()])
            ->selectRaw('day, SUM(impressions) as impressions')->groupBy('day')->get()
            ->mapWithKeys(fn ($stat) => [$stat->day->toDateString() => (int) $stat->getAttribute('impressions')]);

        $days = [];
        $delivered = $before;
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $delivered += (int) $daily->get($day->toDateString(), 0);
            $endOfDay = $day->endOfDay();
            $days[] = [
                'day' => $day,
                'delivered' => $delivered,
                'expected' => (int) round($progress->expectedUntil($endOfDay->greaterThan($progress->now) ? $progress->now : $endOfDay)),
            ];
        }

        $lastDay = $days === [] ? null : $days[array_key_last($days)];

        return ['days' => $days, 'delivered' => $lastDay['delivered'] ?? 0, 'expected' => $lastDay['expected'] ?? 0];
    }
}
