<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\Category;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Il feed personalizzato di §15.7: le date future di ciò che si segue, in
 * ordine di data, con evidenza su ciò che si è salvato.
 *
 * La finestra e l'ordine vengono dal motore (`upcoming()`), il perimetro da
 * `followedBy()`. Questa classe non calcola nulla di temporale: mette insieme
 * le due domande e prepara ciò che serve alla pagina — quali date sono già in
 * agenda, e che cosa proporre a chi non segue ancora niente.
 *
 * **Mai una pagina vuota** (§15.7): senza follow non si mostra un contenitore
 * senza contenuto ma l'avvio guidato, cioè i locali più attivi e le categorie
 * con più date in programma. Quelle due liste sono a loro volta il risultato
 * di un conteggio del motore, quindi "attivi" significa "hanno date future",
 * non "hanno molte righe in tabella".
 */
final class PersonalFeed
{
    /**
     * @return LengthAwarePaginator<int, EventOccurrence>
     */
    public function paginate(City $city, User $user, int $perPage, ?int $page = null): LengthAwarePaginator
    {
        $paginator = EventOccurrenceQuery::for($city)
            ->upcoming()
            ->followedBy($user, includeContentPreferences: true)
            ->paginate($perPage, $page);

        /** @var Collection<int, EventOccurrence> $items */
        $items = new Collection($paginator->items());
        $items->load(['event.venue', 'event.category', 'event.media']);

        return $paginator;
    }

    /**
     * La stessa lista per l'API, sfogliata a cursore come tutte le altre.
     *
     * @return CursorPaginator<int, EventOccurrence>
     */
    public function cursor(City $city, User $user, int $perPage, ?string $cursor = null): CursorPaginator
    {
        return EventOccurrenceQuery::for($city)
            ->upcoming()
            ->followedBy($user, includeContentPreferences: true)
            ->cursorPaginate($perPage, $cursor);
    }

    /**
     * I locali con più date in programma: la prima proposta a chi non segue
     * ancora niente.
     *
     * @return Collection<int, Venue>
     */
    public function suggestedVenues(City $city, int $limit): Collection
    {
        $counts = EventOccurrenceQuery::for($city)->upcoming()->countsByVenue();

        arsort($counts);

        /** @var list<int> $ids */
        $ids = array_slice(array_keys($counts), 0, $limit);

        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<int, Venue> $venues */
        $venues = Venue::query()->whereIn('id', $ids)->get()->sortBy(
            static fn (Venue $venue): int => array_search((int) $venue->getKey(), $ids, true) ?: 0,
        )->values();

        return $venues;
    }

    /**
     * Le categorie con più date in programma. Una categoria senza date non si
     * propone: portare a una lista vuota è peggio che non proporla (§8.6).
     *
     * @return Collection<int, Category>
     */
    public function suggestedCategories(City $city, int $limit): Collection
    {
        $counts = EventOccurrenceQuery::for($city)->upcoming()->countsByCategory();

        arsort($counts);

        /** @var list<int> $ids */
        $ids = array_slice(array_keys($counts), 0, $limit);

        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<int, Category> $categories */
        $categories = Category::query()->whereIn('id', $ids)->get()->sortBy(
            static fn (Category $category): int => array_search((int) $category->getKey(), $ids, true) ?: 0,
        )->values();

        return $categories;
    }
}
