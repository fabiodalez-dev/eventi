<?php

declare(strict_types=1);

namespace App\Support\Sponsorship;

use App\Enums\OccurrenceStatus;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Gli eventi fra cui scegliere quello da sponsorizzare: la parte del
 * selettore della redazione (`App\Filament\Forms\Components\EventPicker`)
 * che non riguarda lo schermo, così la usano allo stesso modo il pannello e
 * l'API dell'app.
 *
 * La regola è `SponsorableEvents` (pubblicati, con una data ancora da fare);
 * qui ci sono la restrizione di una query di partenza, l'evento da tenere
 * anche se non è più sponsorizzabile (la modifica di una campagna esistente),
 * la ricerca per titolo e per nome del locale, l'ordine per prossima data e
 * le colonne calcolate `next_starts_at` e `upcoming_count`.
 */
final class SponsorableEventChoices
{
    public const LIST_LIMIT = 50;

    public const SEARCH_LIMIT = 30;

    /**
     * Le voci della query, dalla prossima data. La query è già ristretta
     * (`sponsorable()`): qui si aggiungono solo ricerca, ordine e righe.
     *
     * @param  Builder<Event>  $events
     * @return Collection<int, Event>
     */
    public static function rows(Builder $events, ?string $search = null, ?CarbonImmutable $now = null): Collection
    {
        $search = trim((string) $search);
        $query = self::withDates($events, $now);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $match) => $match
                ->where('events.title', 'like', $like)
                ->orWhereExists(DB::table('venues')
                    ->whereColumn('venues.id', 'events.venue_id')
                    ->where('venues.name', 'like', $like)));
        }

        return $query
            ->orderByRaw('next_starts_at is null')
            ->orderBy('next_starts_at')
            ->orderByDesc('events.id')
            ->limit($search !== '' ? self::SEARCH_LIMIT : self::LIST_LIMIT)
            ->get();
    }

    /**
     * Un evento della query con le sue date, oppure null se non ci sta.
     *
     * @param  Builder<Event>  $events
     */
    public static function find(Builder $events, mixed $value, ?CarbonImmutable $now = null): ?Event
    {
        if (blank($value) || ! is_scalar($value)) {
            return null;
        }

        $event = self::withDates($events, $now)->whereKey($value)->first();

        return $event instanceof Event ? $event : null;
    }

    /**
     * Quanti eventi sponsorizzabili ha oggi la query: è il numero che «tutti
     * gli eventi» mostra prima di salvare.
     *
     * @param  Builder<Event>  $events
     */
    public static function count(Builder $events, ?CarbonImmutable $now = null): int
    {
        return SponsorableEvents::scope(clone $events, $now)->count();
    }

    /**
     * I primi eventi sponsorizzabili della query, con la prossima data già
     * calcolata: l'anteprima di «tutti gli eventi».
     *
     * @param  Builder<Event>  $events
     * @return list<Event>
     */
    public static function preview(Builder $events, int $limit = 5, ?CarbonImmutable $now = null): array
    {
        return array_values(self::withDates(self::sponsorable($events, null, $now), $now)
            ->orderBy('next_starts_at')
            ->orderByDesc('events.id')
            ->limit($limit)
            ->get()
            ->all());
    }

    /**
     * Gli eventi della query che si possono sponsorizzare (`SponsorableEvents`),
     * oppure l'evento `$keep`.
     *
     * @param  Builder<Event>  $events
     * @return Builder<Event>
     */
    public static function sponsorable(Builder $events, ?int $keep = null, ?CarbonImmutable $now = null): Builder
    {
        /* `select()` sulla query di base toglie anche la sotto-query della
           prossima data che `scope()` aggiunge: resta la sola colonna degli id. */
        $ids = SponsorableEvents::scope(clone $events, $now)->toBase()->select('events.id');

        return Event::query()->where(fn (Builder $either) => $either
            ->whereIn('events.id', $ids)
            ->when($keep !== null, fn (Builder $q) => $q->orWhere('events.id', $keep)));
    }

    /**
     * Prossima data, quante date restano, locandina, locale e fuso, tutto
     * nella stessa lettura: il numero di query non cambia con le voci.
     *
     * @param  Builder<Event>  $events
     * @return Builder<Event>
     */
    public static function withDates(Builder $events, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now('UTC');
        $query = clone $events;

        if ($query->getQuery()->columns === null) {
            $query->select('events.*');
        }

        return $query
            ->addSelect([
                'next_starts_at' => self::validDates($now)->selectRaw('min(event_occurrences.starts_at)'),
                'upcoming_count' => self::validDates($now)->selectRaw('count(*)'),
            ])
            ->with(['media', 'venue:id,name', 'city:id,timezone']);
    }

    /** Le date ancora da fare (o in corso) di un evento, con la regola di `SponsorableEvents`. */
    private static function validDates(CarbonImmutable $now): QueryBuilder
    {
        return DB::table('event_occurrences')
            ->whereColumn('event_occurrences.event_id', 'events.id')
            ->whereNull('event_occurrences.deleted_at')
            ->whereIn('event_occurrences.status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::SoldOut->value])
            ->where('event_occurrences.effective_ends_at', '>', $now);
    }
}
