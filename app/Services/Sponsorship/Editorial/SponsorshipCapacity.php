<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Editorial;

use App\Enums\OccurrenceStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Models\City;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Services\Sponsorship\Delivery\Pacer;
use App\Services\Sponsorship\Delivery\UnitReach;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Quanto può dare uno spazio in un periodo, e chi lo occupa già.
 *
 * **La stima** è la previsione del traffico dello spazio (`TrafficForecast`,
 * la stessa del pacing) sommata da adesso, o dall'inizio se viene dopo, alla
 * fine. Sono le occasioni dello spazio, vendute o no: è il massimo che un
 * obiettivo può chiedere. Sotto i 14 giorni di dati è «provvisoria», e senza
 * dati è il valore di configurazione, che è un'ipotesi e non una misura.
 *
 * **Le soglie.** Sopra il 60% della stima il modulo avvisa (l'unità chiederà
 * più di metà dello spazio), sopra `quota_max` (l'80%) blocca: il motore non
 * dà mai a un'unità più di quella parte dello spazio (`Pacer`), quindi un
 * obiettivo più grande sarebbe «a rischio» dal primo piano, un impegno che
 * non si può mantenere.
 *
 * **Fino a quando.** Come il motore, la stima si ferma alla fine vera
 * (`Pacer::trueEnd()`): per «un evento» che finisce prima della campagna,
 * all'ultima data che lo tiene nella collocazione (`UnitReach`), non alla
 * fine del periodo scritta nel modulo.
 */
final class SponsorshipCapacity
{
    public const WARNING_SHARE = 0.6;

    public function __construct(private readonly SponsorshipProgress $progress) {}

    /** La parte della stima oltre la quale l'obiettivo si blocca: `quota_max`. */
    public static function blockShare(): float
    {
        return app(Pacer::class)->quotaMax();
    }

    /**
     * L'ultimo istante in cui un evento può comparire in una collocazione:
     * l'inizio o la fine della sua ultima data in programma.
     */
    public static function lastCoveredFor(?int $eventId, ?SponsorshipPlacement $placement): ?CarbonImmutable
    {
        if ($eventId === null || $placement === null) {
            return null;
        }

        $last = DB::table('event_occurrences')
            ->where('event_id', $eventId)
            ->whereNull('deleted_at')
            ->whereIn('status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::SoldOut->value])
            ->selectRaw('MAX(starts_at) AS last_start, MAX(effective_ends_at) AS last_end')
            ->first();

        return UnitReach::lastCovered(
            $placement,
            is_string($last?->last_start) ? CarbonImmutable::parse($last->last_start, 'UTC') : null,
            is_string($last?->last_end) ? CarbonImmutable::parse($last->last_end, 'UTC') : null,
        );
    }

    /**
     * @return array{views: int, days: int, provisional: bool}|null
     */
    public function estimate(?City $city, ?SponsorshipPlacement $placement, ?CarbonImmutable $start, ?CarbonImmutable $end, ?CarbonImmutable $now = null, ?CarbonImmutable $lastCovered = null): ?array
    {
        if ($city === null || $placement === null || $start === null || $end === null || ! $end->greaterThan($start)) {
            return null;
        }

        $now ??= CarbonImmutable::now('UTC');
        $timezone = (string) ($city->timezone ?: config('app.timezone'));
        $forecast = $this->progress->forecast($city, $placement, $now);
        $from = ($start->greaterThan($now) ? $start : $now)->setTimezone($timezone);
        $to = Pacer::trueEnd($end, $lastCovered)->setTimezone($timezone);

        return [
            'views' => $to->greaterThan($from) ? (int) floor($forecast->between($from, $to)) : 0,
            'days' => $forecast->days(),
            'provisional' => $forecast->isProvisional(),
        ];
    }

    /** `ok`, `warning` (oltre il 60% della stima) o `blocked` (oltre `quota_max`, l'80%). */
    public static function verdict(int $goal, int $estimate): string
    {
        if ($estimate <= 0 || $goal > $estimate * self::blockShare()) {
            return 'blocked';
        }

        return $goal > $estimate * self::WARNING_SHARE ? 'warning' : 'ok';
    }

    /**
     * Chi occupa già la collocazione nel periodo, nella città: una voce per
     * unità (abilitazione o campagna singola), le bozze escluse.
     *
     * @return list<array{key: string, name: string, kind: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, goal: int|null, events: int}>
     */
    public function occupants(?City $city, ?SponsorshipPlacement $placement, ?CarbonImmutable $start, ?CarbonImmutable $end, int $limit = 8): array
    {
        if ($city === null || $placement === null || $start === null || $end === null) {
            return [];
        }

        $units = [];

        $grants = SponsorshipGrant::query()
            ->with(['venue:id,name,city_id', 'organizer:id,name,city_id'])
            ->withCount('sponsorships')
            ->where('placement', $placement)
            ->where('enabled', true)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->where(fn (Builder $q) => $q
                ->whereHas('venue', fn (Builder $venue) => $venue->where('city_id', $city->getKey()))
                ->orWhereHas('organizer', fn (Builder $organizer) => $organizer->where('city_id', $city->getKey()))
                ->orWhereHas('sponsorships', fn (Builder $rows) => $rows->where('city_id', $city->getKey())))
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        foreach ($grants as $grant) {
            $units[] = [
                'key' => $grant->unitKey(),
                'name' => $grant->sponsorName(),
                'kind' => 'grant',
                'starts_at' => $grant->starts_at,
                'ends_at' => $grant->ends_at,
                'goal' => $grant->impressions_goal,
                'events' => (int) $grant->getAttribute('sponsorships_count'),
            ];
        }

        $singles = Sponsorship::query()
            ->whereNull('sponsorship_grant_id')
            ->where('city_id', $city->getKey())
            ->where('placement', $placement)
            ->where('status', '!=', SponsorshipStatus::Draft)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        foreach ($singles as $row) {
            $units[] = [
                'key' => $row->unitKey(),
                'name' => (string) $row->advertiser_name,
                'kind' => 'single',
                'starts_at' => CarbonImmutable::instance($row->starts_at),
                'ends_at' => CarbonImmutable::instance($row->ends_at),
                'goal' => $row->impressions_goal,
                'events' => 1,
            ];
        }

        usort($units, fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);

        return array_slice($units, 0, $limit);
    }
}
