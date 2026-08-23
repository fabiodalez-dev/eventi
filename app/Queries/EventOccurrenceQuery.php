<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\TimeOfDay;
use App\Models\Category;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\Tag;
use App\Models\Venue;
use App\Services\Geo\GeoQueryInterface;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * Il motore temporale della piattaforma (§8). Sito, API, calendario, feed,
 * notifiche e futura applicazione mobile interrogano le occorrenze **solo** da
 * qui: se "oggi", "stasera", "in corso" o "inizia tra poco" vengono ricalcolati
 * altrove, in sei mesi esisteranno due definizioni divergenti.
 *
 * ```php
 * EventOccurrenceQuery::for($city)->tonight()->inCategories(['musica'])->get();
 * ```
 *
 * Tre invarianti governano la classe:
 *
 * - **"Adesso" è sempre `now($city->timezone)`**, mai l'ora del server (§8.1).
 * - **Le finestre di giornata si leggono da `business_date`**, la colonna
 *   calcolata da `EventOccurrenceObserver`: è ciò che rende venerdì sera un
 *   concerto che finisce sabato alle 3:00 (§8.2).
 * - **Le finestre istantanee** ("in corso", "inizia tra poco") confrontano
 *   `starts_at` ed `effective_ends_at` con l'istante UTC corrispondente.
 *
 * La base della query è già ristretta agli eventi pubblicati della città: una
 * bozza non deve poter comparire in nessuna finestra pubblica.
 */
final class EventOccurrenceQuery
{
    /**
     * Ora locale a partire dalla quale un'occorrenza appartiene a "stasera" (§8.4).
     */
    private const EVENING_HOUR = 17;

    /**
     * Alias della distanza in metri, disponibile sui risultati dopo `near()`.
     */
    public const DISTANCE_ALIAS = 'distance_m';

    /**
     * Alias del criterio "in evidenza adesso", usato da `orderByRelevance()`.
     */
    private const FEATURED_ALIAS = 'is_promoted';

    /** @var Builder<EventOccurrence> */
    private Builder $query;

    private readonly CarbonImmutable $now;

    private OccurrenceOrdering $ordering = OccurrenceOrdering::Chronological;

    /** @var array{lat: float, lng: float}|null */
    private ?array $origin = null;

    private function __construct(private readonly City $city)
    {
        $this->now = CarbonImmutable::now($city->timezone);
        $this->query = $this->baseQuery();
    }

    public static function for(City $city): self
    {
        return new self($city);
    }

    // ---------------------------------------------------------------- finestre

    /**
     * Giornata evento corrente della città.
     */
    public function today(): self
    {
        return $this->onDate($this->now);
    }

    /**
     * Giornata evento corrente, dalle 17:00 locali in poi (§8.4).
     */
    public function tonight(): self
    {
        $this->today();

        [$expression, $bindings] = $this->localStartsAt();

        $this->query->whereRaw(
            sprintf('HOUR(%s) >= ?', $expression),
            [...$bindings, self::EVENING_HOUR],
        );

        return $this;
    }

    public function tomorrow(): self
    {
        return $this->onDate($this->now->addDay());
    }

    /**
     * Venerdì, sabato e domenica della settimana corrente, saltando i giorni
     * già passati: chi chiede "questo weekend" di sabato non vuole vedere gli
     * eventi di venerdì.
     */
    public function weekend(): self
    {
        $friday = $this->now->startOfWeek(CarbonImmutable::MONDAY)->addDays(4);

        $days = [];

        foreach ([0, 1, 2] as $offset) {
            $day = $friday->addDays($offset);

            if ($day->format('Y-m-d') >= $this->now->format('Y-m-d')) {
                $days[] = $day->format('Y-m-d');
            }
        }

        $this->query->whereIn('event_occurrences.business_date', $days);

        return $this;
    }

    /**
     * In corso adesso. Esclude le categorie che non lo sopportano — una mostra
     * aperta tutti i giorni sarebbe permanentemente "in corso" e seppellirebbe
     * i concerti sotto contenuto inerte — e gli eventi di intera giornata.
     */
    public function ongoing(): self
    {
        $now = $this->nowUtc();

        $this->query
            ->where('event_occurrences.starts_at', '<=', $now)
            ->where('event_occurrences.effective_ends_at', '>=', $now)
            ->where('event_occurrences.status', OccurrenceStatus::Scheduled->value)
            ->where('categories.supports_ongoing', true)
            ->where('event_occurrences.is_all_day', false);

        $this->ordering = OccurrenceOrdering::Live;

        return $this;
    }

    /**
     * Comincia entro `city.starting_soon_minutes` (180 di default).
     */
    public function startingSoon(): self
    {
        $now = $this->nowUtc();

        $this->query
            ->where('event_occurrences.starts_at', '>', $now)
            ->where('event_occurrences.starts_at', '<=', $this->now->addMinutes($this->city->starting_soon_minutes)->utc()->format('Y-m-d H:i:s'))
            ->where('event_occurrences.status', OccurrenceStatus::Scheduled->value);

        $this->ordering = OccurrenceOrdering::Live;

        return $this;
    }

    /**
     * Intervallo di giornate evento, estremi compresi.
     */
    public function between(CarbonImmutable|DateTimeInterface|string $from, CarbonImmutable|DateTimeInterface|string $to): self
    {
        $start = $this->toLocalDate($from)->format('Y-m-d');
        $end = $this->toLocalDate($to)->format('Y-m-d');

        $this->query->whereBetween('event_occurrences.business_date', $start <= $end ? [$start, $end] : [$end, $start]);

        return $this;
    }

    public function onDate(CarbonImmutable|DateTimeInterface|string $date): self
    {
        $this->query->where('event_occurrences.business_date', $this->toLocalDate($date)->format('Y-m-d'));

        return $this;
    }

    // ----------------------------------------------------------------- filtri

    /**
     * Entro un raggio in chilometri dal punto dato. Riguarda i soli eventi
     * ospitati da un locale: le coordinate stanno su `venues.location`.
     * Aggiunge alla select la distanza in metri (`distance_m`).
     */
    public function near(float $lat, float $lng, float $radiusKm): self
    {
        $geo = app(GeoQueryInterface::class);

        $this->query->whereNotNull('venues.id');

        $geo->withinRadius($this->query, $lat, $lng, $radiusKm, 'venues.location');
        $geo->distanceSelect($this->query, $lat, $lng, 'venues.location', self::DISTANCE_ALIAS);

        $this->origin = ['lat' => $lat, 'lng' => $lng];

        return $this;
    }

    /**
     * @param  array<int, Category|int|string>  $categories  modelli, id o slug
     */
    public function inCategories(array $categories): self
    {
        return $this->restrictByIdentifiers($categories, 'categories');
    }

    /**
     * @param  array<int, Tag|int|string>  $tags  modelli, id o slug
     */
    public function withTags(array $tags): self
    {
        [$ids, $slugs] = $this->splitIdentifiers($tags);

        if ($ids === [] && $slugs === []) {
            return $this;
        }

        $this->query->whereExists(function (QueryBuilder $sub) use ($ids, $slugs): void {
            $sub->from('event_tag')
                ->join('tags', 'tags.id', '=', 'event_tag.tag_id')
                ->whereColumn('event_tag.event_id', 'events.id')
                ->where(function (QueryBuilder $match) use ($ids, $slugs): void {
                    if ($ids !== []) {
                        $match->orWhereIn('tags.id', $ids);
                    }

                    if ($slugs !== []) {
                        $match->orWhereIn('tags.slug', $slugs);
                    }
                });
        });

        return $this;
    }

    public function priceFree(): self
    {
        $this->query->where('events.price_type', PriceType::Free->value);

        return $this;
    }

    /**
     * Gratuiti, a offerta libera e a pagamento fino all'importo indicato.
     */
    public function priceMax(float|int $amount): self
    {
        $this->query->where(function (Builder $price) use ($amount): void {
            $price->whereIn('events.price_type', [PriceType::Free->value, PriceType::Donation->value])
                ->orWhere(function (Builder $paid) use ($amount): void {
                    $paid->whereNotNull('events.price_min')->where('events.price_min', '<=', $amount);
                });
        });

        return $this;
    }

    /**
     * Fascia oraria locale dell'inizio: giorno, sera o notte.
     */
    public function timeOfDay(TimeOfDay|string $band): self
    {
        $band = $band instanceof TimeOfDay ? $band : TimeOfDay::from($band);

        [$expression, $bindings] = $this->localStartsAt();
        $hour = sprintf('HOUR(%s)', $expression);

        $this->query->whereRaw(
            $band->crossesMidnight()
                ? sprintf('(%s >= ? OR %s < ?)', $hour, $hour)
                : sprintf('(%s >= ? AND %s < ?)', $hour, $hour),
            [...$bindings, $band->startHour(), ...$bindings, $band->endHour()],
        );

        return $this;
    }

    public function atVenue(Venue|int $venue): self
    {
        $this->query->where('events.venue_id', $venue instanceof Venue ? $venue->getKey() : $venue);

        return $this;
    }

    /**
     * Ricerca testuale su titolo, sottotitolo, descrizione breve, organizzatore
     * e nome del locale.
     */
    public function search(string $term): self
    {
        $term = trim($term);

        if ($term === '') {
            return $this;
        }

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        $this->query->where(function (Builder $match) use ($pattern): void {
            $match->where('events.title', 'like', $pattern)
                ->orWhere('events.subtitle', 'like', $pattern)
                ->orWhere('events.short_description', 'like', $pattern)
                ->orWhere('events.organizer_name', 'like', $pattern)
                ->orWhere('venues.name', 'like', $pattern);
        });

        return $this;
    }

    // ------------------------------------------------------------ ordinamento

    /**
     * In evidenza e punteggio redazionale prima della cronologia.
     */
    public function orderByRelevance(): self
    {
        $this->ordering = OccurrenceOrdering::Relevance;

        return $this;
    }

    // ------------------------------------------------------------- esecuzione

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function get(): Collection
    {
        return $this->build()->get();
    }

    /**
     * @return LengthAwarePaginator<int, EventOccurrence>
     */
    public function paginate(int $perPage = 24, ?int $page = null): LengthAwarePaginator
    {
        return $this->build()->paginate(perPage: $perPage, page: $page);
    }

    /**
     * @return CursorPaginator<int, EventOccurrence>
     */
    public function cursorPaginate(int $perPage = 24, ?string $cursor = null): CursorPaginator
    {
        return $this->build()->cursorPaginate(perPage: $perPage, cursor: $cursor);
    }

    // ----------------------------------------------------------------- interno

    /**
     * @return Builder<EventOccurrence>
     */
    private function baseQuery(): Builder
    {
        return EventOccurrence::query()
            ->select('event_occurrences.*')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->join('categories', 'categories.id', '=', 'events.category_id')
            ->leftJoin('venues', function (JoinClause $join): void {
                $join->on('venues.id', '=', 'events.venue_id')->whereNull('venues.deleted_at');
            })
            ->where('events.city_id', $this->city->getKey())
            ->where('events.status', EventStatus::Published->value)
            ->whereNull('events.deleted_at');
    }

    /**
     * L'ordinamento si applica qui e non nei filtri: `get()` chiamato due volte
     * non deve accumulare due volte le stesse clausole.
     *
     * @return Builder<EventOccurrence>
     */
    private function build(): Builder
    {
        $query = clone $this->query;

        match ($this->ordering) {
            OccurrenceOrdering::Live => $this->applyLiveOrdering($query),
            OccurrenceOrdering::Relevance => $this->applyRelevanceOrdering($query),
            OccurrenceOrdering::Chronological => $this->applyChronologicalOrdering($query),
        };

        return $query;
    }

    /**
     * §8.5, nell'ordine: già in corso prima di chi deve cominciare, minuti
     * mancanti all'inizio crescenti, distanza, punteggio redazionale, id.
     * L'ultimo criterio non è decorativo: senza, due righe identiche
     * uscirebbero in ordine casuale e i test non sarebbero riproducibili.
     *
     * Il primo criterio non ha bisogno di una clausola propria: chi è già in
     * corso ha `starts_at <= adesso` e chi deve cominciare `starts_at > adesso`,
     * quindi l'ordine crescente di `starts_at` — che è il secondo criterio —
     * mette per costruzione i primi davanti ai secondi. Una `CASE` aggiuntiva
     * non cambierebbe una riga di risultato e renderebbe l'ordinamento non
     * esprimibile da `cursorPaginate()`, che sa costruire il proprio cursore
     * solo su colonne, non su espressioni grezze.
     *
     * @param  Builder<EventOccurrence>  $query
     */
    private function applyLiveOrdering(Builder $query): void
    {
        $query->orderBy('event_occurrences.starts_at');

        $this->applyDistanceOrdering($query);

        $query->addSelect('events.editorial_score')
            ->orderByDesc('events.editorial_score')
            ->orderBy('event_occurrences.id');
    }

    /**
     * In evidenza (e non scaduto) prima di tutto, poi punteggio redazionale,
     * cronologia e id.
     *
     * "In evidenza adesso" non è una colonna ma una condizione su due campi:
     * entra nella select come alias e da lì ordina. La forma è scelta perché
     * `cursorPaginate()` sappia risalire dall'alias all'espressione originale
     * e costruire il cursore: con un `orderByRaw` non può, e la seconda pagina
     * di ogni lista ordinata così finisce in errore.
     *
     * L'istante è scritto nell'espressione invece di essere legato a un
     * parametro perché un segnaposto dentro l'alias verrebbe copiato nel
     * `where` del cursore senza il proprio binding, disallineando tutti gli
     * altri. Non è un dato d'ingresso: è `$this->now` formattato.
     *
     * @param  Builder<EventOccurrence>  $query
     */
    private function applyRelevanceOrdering(Builder $query): void
    {
        $query->selectRaw(sprintf(
            "(CASE WHEN events.is_featured = 1 AND (events.featured_until IS NULL OR events.featured_until >= '%s') THEN 0 ELSE 1 END) as %s",
            $this->nowUtc(),
            self::FEATURED_ALIAS,
        ))
            ->addSelect('events.editorial_score')
            ->orderBy(self::FEATURED_ALIAS)
            ->orderByDesc('events.editorial_score')
            ->orderBy('event_occurrences.starts_at')
            ->orderBy('event_occurrences.id');
    }

    /**
     * @param  Builder<EventOccurrence>  $query
     */
    private function applyChronologicalOrdering(Builder $query): void
    {
        $query->orderBy('event_occurrences.starts_at')
            ->orderBy('event_occurrences.id');
    }

    /**
     * La distanza ordina le sole sezioni dal vivo, ed è il terzo criterio di
     * §8.5: nelle liste cronologiche resta un dato esposto (`distance_m`), non
     * un ordinamento — chi cerca "oggi" vuole l'ordine del tempo.
     *
     * @param  Builder<EventOccurrence>  $query
     */
    private function applyDistanceOrdering(Builder $query): void
    {
        if ($this->origin !== null) {
            $query->orderBy(self::DISTANCE_ALIAS);
        }
    }

    /**
     * Espressione SQL che riporta `starts_at` (UTC) all'ora locale della città.
     *
     * `CONVERT_TZ` con nome di fuso richiede le tabelle dei fusi caricate nel
     * server, che sulla shared hosting non sono garantite; uno scostamento
     * fisso sbaglierebbe di un'ora per metà anno. Si genera quindi un `CASE`
     * dai cambi di ora reali del fuso, presi dal database dei fusi di PHP.
     *
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function localStartsAt(): array
    {
        $column = 'event_occurrences.starts_at';
        $transitions = (new DateTimeZone($this->city->timezone))->getTransitions(
            $this->now->subYears(2)->getTimestamp(),
            $this->now->addYears(5)->getTimestamp(),
        );

        if ($transitions === []) {
            return [$column, []];
        }

        $sql = 'CASE';
        $bindings = [];

        foreach (array_slice($transitions, 1) as $index => $transition) {
            $sql .= sprintf(' WHEN %s < ? THEN DATE_ADD(%s, INTERVAL ? SECOND)', $column, $column);
            $bindings[] = CarbonImmutable::createFromTimestamp($transition['ts'], 'UTC')->format('Y-m-d H:i:s');
            $bindings[] = (int) $transitions[$index]['offset'];
        }

        $sql .= sprintf(' ELSE DATE_ADD(%s, INTERVAL ? SECOND) END', $column);
        $bindings[] = (int) $transitions[count($transitions) - 1]['offset'];

        return [$sql, $bindings];
    }

    /**
     * Restringe la query a una lista di id o slug su una tabella già in join.
     *
     * @param  array<int, Category|Tag|int|string>  $values
     */
    private function restrictByIdentifiers(array $values, string $table): self
    {
        [$ids, $slugs] = $this->splitIdentifiers($values);

        if ($ids === [] && $slugs === []) {
            return $this;
        }

        $this->query->where(function (Builder $match) use ($ids, $slugs, $table): void {
            if ($ids !== []) {
                $match->orWhereIn($table.'.id', $ids);
            }

            if ($slugs !== []) {
                $match->orWhereIn($table.'.slug', $slugs);
            }
        });

        return $this;
    }

    /**
     * @param  array<int, Category|Tag|int|string>  $values
     * @return array{0: array<int, int>, 1: array<int, string>}
     */
    private function splitIdentifiers(array $values): array
    {
        $ids = [];
        $slugs = [];

        foreach ($values as $value) {
            if ($value instanceof Category || $value instanceof Tag) {
                $ids[] = (int) $value->getKey();

                continue;
            }

            if (is_int($value) || ctype_digit($value)) {
                $ids[] = (int) $value;

                continue;
            }

            $slugs[] = $value;
        }

        return [$ids, $slugs];
    }

    private function toLocalDate(CarbonImmutable|DateTimeInterface|string $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone($this->city->timezone);
        }

        return CarbonImmutable::parse($value, $this->city->timezone);
    }

    private function nowUtc(): string
    {
        return $this->now->utc()->format('Y-m-d H:i:s');
    }
}
