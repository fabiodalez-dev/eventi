<?php

declare(strict_types=1);

namespace App\Services\Feeds;

use App\DTOs\EventFilters;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Services\Search\EventFinder;
use App\Services\Seo\EventListingMeta;
use Illuminate\Database\Eloquent\Collection;

/**
 * Che cosa esce dai feed di §11.10 — il calendario `.ics` e l'RSS.
 *
 * Un feed è la stessa lista di `/eventi` vista da fuori: gli stessi filtri
 * (città, categoria, tag, locale) e le stesse date scelte dal motore
 * temporale. Cambia soltanto il formato, e il fatto che qualcuno lo rilegga
 * da solo ogni giorno senza tornare sul sito.
 *
 * Sopra ai filtri chiesti si applica sempre una finestra massima: un
 * calendario sottoscritto che portasse dentro due anni di date si riempirebbe
 * di appuntamenti che cambieranno ancora, e nessuno li ripulirebbe.
 */
final class EventFeed
{
    public function __construct(
        private readonly EventFinder $finder,
        private readonly EventListingMeta $meta,
    ) {}

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function occurrences(City $city, EventFilters $filters, ?int $limit = null): Collection
    {
        /*
         * `nextDays()` si somma alla finestra già chiesta invece di
         * sostituirla: chi sottoscrive "questo weekend" continua a ricevere il
         * weekend, e chi non chiede niente riceve i prossimi tre mesi e non
         * l'intero futuro.
         */
        $occurrences = $this->finder
            ->query($city, $filters)
            ->nextDays(config()->integer('feeds.days_ahead'))
            ->get()
            ->take($limit ?? config()->integer('feeds.max_items'));

        /** @var Collection<int, EventOccurrence> $occurrences */
        $occurrences = $occurrences->values();

        return $occurrences->load(['event.venue', 'event.category', 'event.city', 'event.media']);
    }

    /**
     * Il nome del feed: la stessa frase che intitola la lista corrispondente,
     * così che un calendario sottoscritto si chiami "Musica dal vivo gratis a
     * Padova" e non "Eventi" come tutti gli altri.
     */
    public function name(City $city, EventFilters $filters): string
    {
        return $this->meta->build($city, $filters, 0)->heading;
    }
}
