<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\OccurrenceStatus;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Le voci del selettore di eventi dei pannelli (`EventSelect`).
 *
 * Ogni voce è la stessa riga dappertutto: locandina, titolo, e sotto «sab 22
 * nov, 21:00 · Officina dei Portici» — mai un ID, uno slug o una data in
 * cifre. Le voci si dividono in «Prossimi» (dalla data più vicina) e «Passati»
 * (dal più recente).
 *
 * **Una lettura per gruppo, non una per voce.** La prossima data, l'ultima
 * passata e quante date restano arrivano come sotto-query nella stessa
 * lettura degli eventi; locandina, locale e fuso della città si caricano
 * insieme. Il numero di query non cambia fra 5 e 500 eventi.
 *
 * La query che si riceve è quella che chi guarda può già vedere:
 * l'autorizzazione resta dov'è, qui si legge e basta.
 */
final class EventOptions
{
    public const UPCOMING_LIMIT = 15;

    public const PAST_LIMIT = 10;

    public const SEARCH_LIMIT = 30;

    /**
     * @param  Builder<Event>  $events
     * @return array<string, array<int|string, string>>
     */
    public static function grouped(Builder $events, ?string $search = null, bool $showVenue = true, bool $upcomingOnly = false): array
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

        $groups = [__('pannelli.select.upcoming') => self::labels($upcoming, $showVenue)];

        if ($upcomingOnly) {
            return array_filter($groups);
        }

        $pastLimit = $search !== '' ? self::SEARCH_LIMIT - $upcoming->count() : self::PAST_LIMIT;

        if ($pastLimit > 0) {
            $past = (clone $base)
                ->whereNotExists(self::occurrences($now, future: true))
                ->whereExists(self::occurrences($now, future: false))
                ->orderByDesc('last_starts_at')
                ->limit($pastLimit)
                ->get();

            $groups[__('pannelli.select.past')] = self::labels($past, $showVenue);
        }

        return array_filter($groups);
    }

    /**
     * La riga del valore già scelto, oppure null se l'evento non è fra quelli
     * che la query lascia vedere: così un valore scritto a mano non passa.
     *
     * @param  Builder<Event>  $events
     */
    public static function labelFor(Builder $events, mixed $value, bool $showVenue = true): ?string
    {
        if (blank($value) || ! is_scalar($value)) {
            return null;
        }

        $event = self::withDates($events, CarbonImmutable::now('UTC'))->whereKey($value)->first();

        return $event instanceof Event ? self::render($event, $showVenue) : null;
    }

    /** La riga di un evento già caricato (le date si leggono se mancano). */
    public static function label(Event $event, bool $showVenue = true): string
    {
        if (! array_key_exists('next_starts_at', $event->getAttributes())) {
            $loaded = self::withDates(Event::query()->withoutGlobalScopes(), CarbonImmutable::now('UTC'))
                ->whereKey($event->getKey())
                ->first();
            $event = $loaded instanceof Event ? $loaded : $event;
        }

        return self::render($event, $showVenue);
    }

    /**
     * Il titolo nudo di un evento della query, per gli indicatori dei filtri.
     *
     * @param  Builder<Event>  $events
     */
    public static function title(Builder $events, mixed $value): ?string
    {
        if (blank($value) || ! is_scalar($value)) {
            return null;
        }

        $title = (clone $events)->whereKey($value)->value($events->getModel()->qualifyColumn('title'));

        return is_string($title) ? $title : null;
    }

    /**
     * @param  Builder<Event>  $events
     * @return Builder<Event>
     */
    private static function withDates(Builder $events, CarbonImmutable $now): Builder
    {
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

    /**
     * @param  iterable<Event>  $events
     * @return array<int|string, string>
     */
    private static function labels(iterable $events, bool $showVenue): array
    {
        $labels = [];

        foreach ($events as $event) {
            $labels[$event->getKey()] = self::render($event, $showVenue);
        }

        return $labels;
    }

    private static function render(Event $event, bool $showVenue): string
    {
        $next = $event->getAttribute('next_starts_at');
        $upcoming = (int) $event->getAttribute('upcoming_count');

        return view('filament.partials.event-option', [
            'event' => $event,
            'when' => $next ?? $event->getAttribute('last_starts_at'),
            'more' => max(0, $upcoming - 1),
            'venue' => $showVenue ? $event->venue?->name : null,
        ])->render();
    }
}
