<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\EventStatus;
use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
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
 * di ricerca, poi il motore temporale integra corrispondenze parziali e tag.
 * Chiedere direttamente a
 * Scout le occorrenze significherebbe riscrivere qui la definizione di "futuro"
 * (§8.1), e diventerebbe la seconda.
 *
 * Il numero di eventi chiesti a Scout è più alto di quelli mostrati: fra i
 * risultati testuali ce ne sono di conclusi, e vanno scartati **dopo** —
 * chiederne dieci per mostrarne dieci ne farebbe uscire tre.
 */
final class SiteSearch
{
    /** @return Collection<int, Organizer> */
    public function organizers(City $city, string $term, int $limit): Collection
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return Organizer::query()->visibleInCity($city)
            ->where(fn ($query) => $query->where('name', 'like', $pattern)->orWhere('description', 'like', $pattern))
            ->orderBy('name')->limit($limit)->get();
    }

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

        /** @var Collection<int, EventOccurrence> $occurrences */
        $occurrences = EventOccurrenceQuery::for($city)
            ->upcoming()
            ->search($term, $ids)
            ->firstPerEvent($limit);

        return $occurrences->load(['event.venue', 'event.category', 'event.media']);
    }

    /**
     * Scout cerca nei campi del locale; LIKE integra le parole ancora
     * incomplete nelle descrizioni. I vincoli pubblici restano fuori dall'OR.
     *
     * @return Collection<int, Venue>
     */
    public function venues(City $city, string $term, int $limit): Collection
    {
        $ids = Venue::search($term)
            ->where('status', VenueStatus::Approved->value)
            ->where('city_id', $city->getKey())
            ->take($limit)
            ->keys();

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $venues = Venue::query()
            ->where('status', VenueStatus::Approved)
            ->where('city_id', $city->getKey())
            ->where(fn (Builder $query) => $query->whereIn('id', $ids)->orWhere('description', 'like', $pattern))
            ->with('media')
            ->orderBy('name')
            ->limit($limit)
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
