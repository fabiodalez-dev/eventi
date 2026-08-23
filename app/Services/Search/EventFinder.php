<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\DTOs\EventFilters;
use App\Enums\EventSort;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Traduce i filtri di una lista pubblica in chiamate al motore temporale.
 *
 * È l'unico punto in cui la query string diventa una query: i controller
 * ricevono un `EventFilters` e chiedono qui i risultati, e nessuna finestra
 * temporale viene mai ricalcolata fuori da `EventOccurrenceQuery` (§8).
 *
 * Quando la richiesta non porta alcuna data, la finestra predefinita è
 * `upcoming()`: una lista pubblica parla del futuro, l'archivio si chiede
 * esplicitamente.
 */
final class EventFinder
{
    public function query(City $city, EventFilters $filters): EventOccurrenceQuery
    {
        $query = EventOccurrenceQuery::for($city);

        $this->applyDates($query, $filters);
        $this->applyTaxonomy($query, $filters);
        $this->applyPlace($query, $filters);
        $this->applyFeatures($query, $filters);
        $this->applyOrdering($query, $filters);

        if ($filters->q !== '') {
            $query->search($filters->q);
        }

        return $query;
    }

    /**
     * @return LengthAwarePaginator<int, EventOccurrence>
     */
    public function paginate(City $city, EventFilters $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $paginator = $this->query($city, $filters)
            ->paginate($perPage ?? config()->integer('eventi.per_page'))
            ->withQueryString();

        /* `load()` riempie le relazioni sugli stessi oggetti che il paginatore
           ha in pancia: raccoglierli in una collezione nuova non li duplica. */
        $this->hydrate(new Collection($paginator->items()));

        return $paginator;
    }

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function take(City $city, EventFilters $filters, int $limit): Collection
    {
        /** @var Collection<int, EventOccurrence> $occurrences */
        $occurrences = $this->query($city, $filters)->get()->take($limit);

        return $this->hydrate($occurrences);
    }

    /**
     * Carica in un colpo solo ciò che la card legge: evento, locale, categoria
     * e locandina. Senza, ogni card interrogherebbe il database per conto suo.
     *
     * @param  Collection<int, EventOccurrence>  $occurrences
     * @return Collection<int, EventOccurrence>
     */
    public function hydrate(Collection $occurrences): Collection
    {
        return $occurrences->load(['event.venue', 'event.category', 'event.media']);
    }

    private function applyDates(EventOccurrenceQuery $query, EventFilters $filters): void
    {
        if ($filters->preset !== null) {
            $filters->preset->applyTo($query);

            return;
        }

        if ($filters->date !== null) {
            $query->onDate($filters->date);

            return;
        }

        if ($filters->from !== null || $filters->to !== null) {
            $query->between($filters->from ?? $filters->to, $filters->to ?? $filters->from);

            return;
        }

        $query->upcoming();
    }

    private function applyTaxonomy(EventOccurrenceQuery $query, EventFilters $filters): void
    {
        if ($filters->categories !== []) {
            $query->inCategories($filters->categories);
        }

        if ($filters->tags !== []) {
            $query->withTags($filters->tags);
        }

        /*
         * "Adatto alle famiglie" non è un flag dello schema: è la tassonomia.
         * Una seconda chiamata a `inCategories()` aggiunge una condizione in
         * AND, quindi chiedere "musica" e "famiglie" insieme dà l'intersezione
         * — che è ciò che si aspetta chi accende due filtri.
         */
        if ($filters->family) {
            $query->inCategories(config()->array('eventi.family_categories'));
        }

        $filters->price?->applyTo($query);

        if ($filters->time !== null) {
            $query->timeOfDay($filters->time);
        }
    }

    private function applyPlace(EventOccurrenceQuery $query, EventFilters $filters): void
    {
        if ($filters->municipality !== null) {
            $query->inMunicipality($filters->municipality);
        }

        if ($filters->venue !== null) {
            $query->atVenueSlug($filters->venue);
        }

        if ($filters->hasPosition() && $filters->lat !== null && $filters->lng !== null) {
            $query->near($filters->lat, $filters->lng, $filters->radius ?? (float) config()->array('eventi.distance_options')[0]);
        }
    }

    private function applyFeatures(EventOccurrenceQuery $query, EventFilters $filters): void
    {
        if ($filters->outdoor) {
            $query->outdoor();
        }

        if ($filters->accessible) {
            $query->accessible();
        }
    }

    private function applyOrdering(EventOccurrenceQuery $query, EventFilters $filters): void
    {
        match ($filters->sort) {
            EventSort::Relevance => $query->orderByRelevance(),
            EventSort::Distance => $query->orderByDistance(),
            EventSort::Time, null => $query,
        };
    }
}
