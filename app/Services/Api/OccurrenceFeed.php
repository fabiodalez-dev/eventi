<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Enums\ApiInclude;
use App\Http\Requests\Api\V1\EventQueryRequest;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Services\Search\EventFinder;
use App\Support\Api\ApiContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * Il ponte fra i parametri di §13.2 e il motore temporale.
 *
 * È l'unico punto dell'API che traduce una richiesta in una query, ed è il
 * gemello di `EventFinder`, che fa lo stesso per il sito: entrambi passano da
 * `EventOccurrenceQuery` e nessuno dei due sa che cosa sia "stasera" (§8).
 * L'API aggiunge alla lista del sito tre cose che il sito non ha — il tetto
 * di prezzo parametrico, il rettangolo della mappa e `updated_since` per la
 * sincronizzazione offline — e un vocabolario di ordinamento suo.
 */
final class OccurrenceFeed
{
    public function __construct(private readonly EventFinder $finder) {}

    public function query(City $city, EventQueryRequest $request): EventOccurrenceQuery
    {
        $query = $this->finder->query($city, $request->filters());

        $request->price()?->applyTo($query);

        $bounds = $request->bounds();

        if ($bounds !== null) {
            $query->withinBounds($bounds['min_lng'], $bounds['min_lat'], $bounds['max_lng'], $bounds['max_lat']);
        }

        $since = $request->updatedSince();

        if ($since !== null) {
            $query->updatedSince($since);
        }

        $request->sort()?->applyTo($query);

        return $query;
    }

    /**
     * Una pagina di occorrenze già idratata, con il contesto che le
     * accompagna: fuso, inclusioni chieste e salvataggi dell'utente.
     */
    public function page(City $city, EventQueryRequest $request, ?User $user, ?EventOccurrenceQuery $query = null): OccurrencePage
    {
        $paginator = ($query ?? $this->query($city, $request))
            ->cursorPaginate($request->limit(), $request->cursor())
            ->withQueryString();

        /** @var Collection<int, EventOccurrence> $items */
        $items = new Collection($paginator->items());

        $includes = $request->includes();

        $this->hydrate($items, $includes);

        return new OccurrencePage(
            $paginator,
            ApiContext::forOccurrences($city, $includes, $user, $this->ids($items)),
        );
    }

    /**
     * Carica in un colpo solo ciò che la risposta legge. Senza, una pagina di
     * cinquanta occorrenze farebbe centocinquanta interrogazioni: una per il
     * locale, una per la categoria e una per la locandina di ciascuna.
     *
     * @param  Collection<int, EventOccurrence>  $occurrences
     * @param  list<ApiInclude>  $includes
     */
    public function hydrate(Collection $occurrences, array $includes): void
    {
        $relations = ['event.venue', 'event.category', 'event.media'];

        foreach (ApiInclude::relationsFor($includes) as $relation) {
            if (! in_array($relation, $relations, true)) {
                $relations[] = $relation;
            }
        }

        $occurrences->load($relations);
    }

    /**
     * @param  Collection<int, EventOccurrence>  $occurrences
     * @return list<int>
     */
    public function ids(Collection $occurrences): array
    {
        $ids = [];

        foreach ($occurrences as $occurrence) {
            $ids[] = (int) $occurrence->getKey();
        }

        return $ids;
    }
}
