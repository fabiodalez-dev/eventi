<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Dove e fino a quando può comparire un'unità di consegna: le città delle
 * sue righe e la fine dell'ultima data che la tiene nella collocazione.
 *
 * È il punto in comune fra il motore (`DeliveryPlanBuilder`, che ne ricava la
 * quota richiesta) e i pannelli (`SponsorshipProgress`, che ne ricava atteso e
 * stato): se contassero su basi diverse, il pannello direbbe «in anticipo» o
 * «in ritardo» rispetto a un ritmo che il motore non persegue.
 *
 * **Quale data chiude la presenza.** La card della home e il foglio della
 * mappa mostrano solo date che devono ancora cominciare: l'unità esce dal
 * piano quando comincia l'ultima, anche se dura tre giorni. L'apertura e la
 * cima dell'elenco mostrano anche la data in corso: l'unità resta fino alla
 * fine dell'ultima.
 *
 * Legge con il costruttore di query e non con i modelli: nei pannelli di
 * locali e organizzatori Filament restringe i modelli al tenant.
 */
final class UnitReach
{
    /** Vero per le collocazioni che mostrano solo date non ancora cominciate. */
    public static function futureOnly(SponsorshipPlacement|string $placement): bool
    {
        $value = $placement instanceof SponsorshipPlacement ? $placement->value : $placement;

        return in_array($value, [SponsorshipPlacement::HomeCard->value, SponsorshipPlacement::MapSheet->value], true);
    }

    /**
     * L'ultimo istante in cui una data tiene l'unità nella collocazione:
     * l'inizio per le collocazioni che mostrano solo date future, la fine per
     * le altre.
     */
    public static function lastCovered(SponsorshipPlacement|string $placement, ?CarbonInterface $lastStart, ?CarbonInterface $lastEnd): ?CarbonImmutable
    {
        $instant = self::futureOnly($placement) ? $lastStart : $lastEnd;

        return $instant === null ? null : CarbonImmutable::instance($instant)->utc();
    }

    /**
     * Per ogni abilitazione: le città in cui può ancora comparire (quelle con
     * una data coperta dopo `$now`; se nessuna, tutte quelle con una data, così
     * un'unità finita ha ancora una base per il pannello) e l'ultima data
     * coperta fra tutte.
     *
     * Contano le righe attive e non nel cestino, con eventi pubblicati e date
     * in programma o esaurite: le stesse condizioni dell'avanzamento.
     *
     * @param  list<int>  $grantIds
     * @return array<int, array{cities: list<int>, last: CarbonImmutable|null}> per id dell'abilitazione
     */
    public function forGrants(array $grantIds, CarbonInterface $now): array
    {
        if ($grantIds === []) {
            return [];
        }

        $rows = DB::table('event_occurrences')
            ->join('sponsorships', 'sponsorships.event_id', '=', 'event_occurrences.event_id')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->whereIn('sponsorships.sponsorship_grant_id', $grantIds)
            ->whereNull('sponsorships.deleted_at')
            ->where('sponsorships.status', SponsorshipStatus::Active->value)
            ->whereNull('event_occurrences.deleted_at')
            ->whereNull('events.deleted_at')
            ->where('events.status', EventStatus::Published->value)
            ->whereIn('event_occurrences.status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::SoldOut->value])
            ->groupBy('sponsorships.sponsorship_grant_id', 'sponsorships.city_id', 'sponsorships.placement')
            ->selectRaw('sponsorships.sponsorship_grant_id AS grant_id, sponsorships.city_id AS city_id, sponsorships.placement AS placement, MAX(event_occurrences.starts_at) AS last_start, MAX(event_occurrences.effective_ends_at) AS last_end')
            ->get();

        $moment = CarbonImmutable::instance($now)->utc();
        $reach = [];

        foreach ($rows as $row) {
            if (! is_numeric($row->grant_id) || ! is_numeric($row->city_id) || ! is_string($row->placement)) {
                continue;
            }

            $last = self::lastCovered(
                $row->placement,
                is_string($row->last_start) ? CarbonImmutable::parse($row->last_start, 'UTC') : null,
                is_string($row->last_end) ? CarbonImmutable::parse($row->last_end, 'UTC') : null,
            );

            $grant = (int) $row->grant_id;
            $reach[$grant] ??= ['all' => [], 'open' => [], 'last' => null];
            $reach[$grant]['all'][(int) $row->city_id] = true;

            if ($last !== null && $last->greaterThan($moment)) {
                $reach[$grant]['open'][(int) $row->city_id] = true;
            }

            if ($last !== null && ($reach[$grant]['last'] === null || $last->greaterThan($reach[$grant]['last']))) {
                $reach[$grant]['last'] = $last;
            }
        }

        $result = [];

        foreach ($reach as $grant => $data) {
            $cities = array_keys($data['open'] !== [] ? $data['open'] : $data['all']);
            sort($cities);
            $result[$grant] = ['cities' => $cities, 'last' => $data['last']];
        }

        return $result;
    }
}
