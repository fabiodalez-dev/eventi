<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\EventStatus;
use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Tag;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * La ricerca del sito (`/cerca`), raggruppata per tipo di cosa trovata:
 * eventi, locali, tag.
 *
 * **Scout dice quali eventi somigliano a ciò che è stato scritto; quali date
 * siano ancora future lo dice il motore temporale.** È la ragione per cui la
 * ricerca degli eventi passa in due tempi: prima gli identificativi dal motore
 * di ricerca, poi `EventOccurrenceQuery::forEvents()`. Chiedere direttamente a
 * Scout le occorrenze significherebbe riscrivere qui la definizione di "futuro"
 * (§8.1), e diventerebbe la seconda.
 *
 * Il numero di eventi chiesti a Scout è più alto di quelli mostrati: fra i
 * risultati testuali ce ne sono di conclusi, e vanno scartati **dopo** —
 * chiederne dieci per mostrarne dieci ne farebbe uscire tre.
 */
final class SiteSearch
{
    /**
     * Quanti eventi chiedere al motore testuale prima di filtrare le date.
     */
    private const EVENT_CANDIDATES = 120;

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function events(City $city, string $term, int $limit): Collection
    {
        $ids = $this->eventIds($city, $term);

        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<int, EventOccurrence> $occurrences */
        $occurrences = EventOccurrenceQuery::for($city)
            ->upcoming()
            ->forEvents($ids)
            ->get()
            ->unique('event_id')
            ->take($limit)
            ->values();

        return $occurrences->load(['event.venue', 'event.category', 'event.media']);
    }

    /**
     * I vincoli si esprimono con `where()` di Scout e non con gli scope del
     * modello: `query()` consegna un costruttore di query **non tipizzato**, e
     * chiamarci sopra uno scope significherebbe rinunciare a sapere se quello
     * scope esiste ancora. `with()` resta lì perché è del costruttore di base.
     *
     * @return Collection<int, Venue>
     */
    public function venues(City $city, string $term, int $limit): Collection
    {
        /** @var Collection<int, Venue> $venues */
        $venues = Venue::search($term)
            ->where('status', VenueStatus::Approved->value)
            ->where('city_id', $city->getKey())
            ->query(fn (Builder $query) => $query->with('media'))
            ->take($limit)
            ->get();

        return $venues;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function tags(string $term, int $limit): Collection
    {
        /** @var Collection<int, Tag> $tags */
        $tags = Tag::search($term)
            ->where('is_approved', true)
            ->take($limit)
            ->get();

        return $tags;
    }

    /**
     * Gli eventi pubblicati della città che corrispondono al testo cercato.
     *
     * @return array<int, int>
     */
    private function eventIds(City $city, string $term): array
    {
        return Event::search($term)
            ->where('city_id', $city->getKey())
            ->where('status', EventStatus::Published->value)
            ->take(self::EVENT_CANDIDATES)
            ->keys()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
