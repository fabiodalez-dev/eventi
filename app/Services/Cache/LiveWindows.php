<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Services\Account\ContentPreferences;
use App\Services\Calendar\MonthCalendar;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Le due finestre che cambiano di minuto in minuto — "In corso adesso" e
 * "Inizia tra poco" — in cache per sessanta secondi con la **chiave
 * arrotondata al quarto d'ora** (§12.3).
 *
 * ## Perché l'arrotondamento è tutto
 *
 * "In corso" dipende dall'istante. Se l'istante finisse nella chiave così
 * com'è, alle 21:03:07 e alle 21:03:08 si chiederebbero due chiavi diverse:
 * ogni richiesta scriverebbe una voce nuova e nessuna leggerebbe mai quella di
 * prima. Si pagherebbe il costo della cache — una scrittura in più per
 * richiesta — senza il beneficio, e la cache diventerebbe un peso.
 *
 * Arrotondando a `floorMinutes(15)`, tutte le richieste dello stesso quarto
 * d'ora chiedono la stessa chiave. Il tempo di vita resta di sessanta secondi,
 * quindi il contenuto non invecchia mai più di un minuto: l'arrotondamento
 * governa **quante chiavi esistono**, il TTL governa **quanto durano**. Sono
 * due manopole diverse e servono tutte e due.
 *
 * ## Che cosa si conserva
 *
 * Gli identificativi, non i modelli. La finestra la calcola
 * `EventOccurrenceQuery` (§8) con la sua interrogazione a più giunzioni; qui
 * resta l'elenco, e le righe si rileggono per chiave primaria con le relazioni
 * che servono alla card. Così un titolo corretto due minuti fa si vede subito,
 * mentre l'appartenenza alla finestra — che è la parte cara — resta salvata.
 */
final class LiveWindows
{
    public const ONGOING = 'in-corso';

    public const STARTING_SOON = 'inizia-tra-poco';

    public const NEXT = 'il-prossimo';

    public const NEXT_PERSONAL = 'il-prossimo-per-te';

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function ongoing(City $city, int $limit): Collection
    {
        return $this->window($city, self::ONGOING, $limit);
    }

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function startingSoon(City $city, int $limit): Collection
    {
        return $this->window($city, self::STARTING_SOON, $limit);
    }

    /**
     * Il prossimo evento che comincia, per la pagina iniziale accanto al
     * calendario. Per chi è collegato e ha scelto o segue qualcosa è il
     * prossimo fra i suoi interessi (le stesse regole del calendario
     * personale); se fra quelli non c'è niente in programma, o se non ha
     * interessi, è il prossimo della città — sempre senza le categorie e i
     * locali che ha nascosto.
     *
     * Restituisce il primo e quelli che seguono («a seguire»), fino a `$limit`.
     *
     * @return array{occurrences: Collection<int, EventOccurrence>, personal: bool}|null
     */
    public function next(City $city, ?User $user, int $limit = 5): ?array
    {
        if ($user !== null && app(MonthCalendar::class)->interests($user) !== null) {
            $personal = $this->window($city, self::NEXT_PERSONAL, $limit, $user);

            if ($personal->isNotEmpty()) {
                return ['occurrences' => $personal, 'personal' => true];
            }
        }

        $occurrences = $this->window($city, self::NEXT, $limit);

        return $occurrences->isEmpty() ? null : ['occurrences' => $occurrences, 'personal' => false];
    }

    /**
     * La chiave di una finestra: città, nome della finestra e **istante
     * arrotondato al quarto d'ora**.
     */
    public static function key(City $city, string $window): string
    {
        $slot = EventOccurrenceQuery::for($city)
            ->now()
            ->floorMinutes(config()->integer('page_cache.live_rounding_minutes'));

        return sprintf('dal-vivo:%d:%s:%s', (int) $city->getKey(), $window, $slot->format('Y-m-d\TH:i'));
    }

    /**
     * Le finestre si leggono con le preferenze di chi guarda (le categorie e i
     * locali nascosti tolgono date), quindi la chiave le deve contenere: senza,
     * la prima persona collegata che apriva la pagina in un quarto d'ora
     * decideva cosa vedevano tutti gli altri, e chi aveva nascosto una
     * categoria se la ritrovava dalla copia di un altro. Chi non ha preferenze
     * condivide la voce della città; la finestra personale è di una persona
     * sola.
     *
     * @return Collection<int, EventOccurrence>
     */
    private function window(City $city, string $window, int $limit, ?User $personal = null): Collection
    {
        $preferences = app(ContentPreferences::class);
        $viewer = $preferences->discoveryUser();
        $hidden = [$preferences->hidden($viewer), $preferences->hiddenVenues($viewer)];
        $suffix = $personal !== null
            ? ':u'.$personal->getKey()
            : ($hidden === [[], []] ? '' : ':p'.substr(sha1((string) json_encode($hidden)), 0, 16));

        /** @var list<int> $ids */
        $ids = Cache::remember(
            self::key($city, $window).':'.$limit.$suffix,
            now()->addSeconds(config()->integer('page_cache.live_ttl_seconds')),
            function () use ($city, $window, $limit, $personal): array {
                $query = EventOccurrenceQuery::for($city);

                $occurrences = match ($window) {
                    self::ONGOING => $query->ongoing()->take($limit),
                    self::STARTING_SOON => $query->startingSoon()->take($limit),
                    self::NEXT_PERSONAL => $personal === null
                        ? new Collection
                        : $query->followedBy($personal, includeContentPreferences: true)->startingNext()->take($limit * 4)->unique('event_id')->take($limit),
                    // Una data per evento: un corso settimanale non deve riempire «a seguire».
                    default => $query->startingNext()->take($limit * 4)->unique('event_id')->take($limit),
                };

                return array_map(
                    static fn (EventOccurrence $occurrence): int => (int) $occurrence->getKey(),
                    $occurrences->all(),
                );
            },
        );

        return $this->hydrate($ids);
    }

    /**
     * Rilegge le righe per chiave primaria conservando **l'ordine della
     * finestra**: `whereKey()` restituisce le righe nell'ordine che preferisce,
     * e l'ordine di "inizia tra poco" è il conto alla rovescia — cioè
     * l'informazione principale della sezione.
     *
     * @param  list<int>  $ids
     * @return Collection<int, EventOccurrence>
     */
    private function hydrate(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $occurrences = EventOccurrence::query()
            ->whereKey($ids)
            ->limit(count($ids))
            ->withCount('interestedUsers as interested_count')
            ->with(['event.venue', 'event.category', 'event.media'])
            ->get()
            ->keyBy(static fn (EventOccurrence $occurrence): int => (int) $occurrence->getKey());

        $ordered = new Collection;

        foreach ($ids as $id) {
            $occurrence = $occurrences->get($id);

            if ($occurrence instanceof EventOccurrence) {
                $ordered->push($occurrence);
            }
        }

        return $ordered;
    }
}
