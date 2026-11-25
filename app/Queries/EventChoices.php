<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\OccurrenceStatus;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Gli eventi fra cui scegliere, divisi in «Prossimi» (dalla data più vicina,
 * con in coda quelli ancora senza date) e «Passati» (dal più recente), con
 * la ricerca per titolo e per nome del locale.
 *
 * È la lettura del selettore di eventi dei pannelli (`EventOptions`, che poi
 * disegna le voci in HTML) e del foglio «Scegli un evento» dell'app di
 * gestione (che le riceve come dati): una sola regola per le due.
 *
 * Una lettura per gruppo, non una per voce: la prossima data, l'ultima
 * passata e quante date restano arrivano come sotto-query; locandina, locale
 * e fuso della città si caricano insieme.
 *
 * La query che si riceve è quella che chi guarda può già vedere:
 * l'autorizzazione resta dov'è, qui si legge e basta.
 */
final class EventChoices
{
    public const UPCOMING_LIMIT = 15;

    public const PAST_LIMIT = 10;

    public const SEARCH_LIMIT = 30;

    /**
     * @param  Builder<Event>  $events
     * @return array{upcoming: Collection<int, Event>, past: Collection<int, Event>|null}
     */
    public static function grouped(Builder $events, ?string $search = null, bool $upcomingOnly = false): array
    {
        $search = trim((string) $search);
        $now = CarbonImmutable::now('UTC');
        $base = self::withDates($events, $now);

        if ($search !== '') {
            self::applySearch($base, $search);
        }

        $upcomingLimit = $search !== '' ? self::SEARCH_LIMIT : self::UPCOMING_LIMIT;

        /* Fra i prossimi stanno anche gli eventi ancora senza date (una bozza
           appena creata), dopo quelli che hanno già una data. */
        $upcoming = (clone $base)
            ->where(fn (Builder $query) => $query
                ->whereExists(self::occurrences($now, future: true))
                ->when(! $upcomingOnly, fn (Builder $query) => $query->orWhereNotExists(self::occurrences(null))))
            ->orderByRaw('next_starts_at is null')
            ->orderBy('next_starts_at')
            ->orderByDesc($base->getModel()->qualifyColumn('id'))
            ->limit($upcomingLimit)
            ->get();

        if ($upcomingOnly) {
            return ['upcoming' => $upcoming, 'past' => null];
        }

        $pastLimit = $search !== '' ? self::SEARCH_LIMIT - $upcoming->count() : self::PAST_LIMIT;
        $past = $pastLimit > 0
            ? (clone $base)
                ->whereNotExists(self::occurrences($now, future: true))
                ->whereExists(self::occurrences($now, future: false))
                ->orderByDesc('last_starts_at')
                ->limit($pastLimit)
                ->get()
            : null;

        return ['upcoming' => $upcoming, 'past' => $past];
    }

    /**
     * La query con le date che servono a una voce: `next_starts_at`,
     * `last_starts_at` e `upcoming_count`, più locandina, locale e città.
     *
     * @param  Builder<Event>  $events
     * @return Builder<Event>
     */
    public static function withDates(Builder $events, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now('UTC');
        $query = clone $events;
        $table = $query->getModel()->getTable();

        if ($query->getQuery()->columns === null) {
            $query->select($table.'.*');
        }

        return $query
            ->addSelect([
                'next_starts_at' => self::occurrences($now, future: true)->selectRaw('min(starts_at)'),
                'last_starts_at' => self::occurrences($now, future: false)->selectRaw('max(starts_at)'),
                'upcoming_count' => self::occurrences($now, future: true)->selectRaw('count(*)'),
            ])
            ->with(['media', 'venue:id,name', 'city:id,timezone']);
    }

    /** Le date valide di un evento (non annullate, non cancellate), future o passate. */
    private static function occurrences(?CarbonImmutable $now, bool $future = true): QueryBuilder
    {
        return DB::table('event_occurrences')
            ->whereColumn('event_occurrences.event_id', 'events.id')
            ->whereNull('event_occurrences.deleted_at')
            ->where('event_occurrences.status', '!=', OccurrenceStatus::Cancelled->value)
            ->when($now !== null, fn (QueryBuilder $query) => $query->where('event_occurrences.starts_at', $future ? '>=' : '<', $now));
    }

    /** @param  Builder<Event>  $query */
    private static function applySearch(Builder $query, string $search): void
    {
        $like = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $query) => $query
            ->where($query->getModel()->qualifyColumn('title'), 'like', $like)
            ->orWhereExists(DB::table('venues')
                ->whereColumn('venues.id', 'events.venue_id')
                ->where('venues.name', 'like', $like)));
    }
}
