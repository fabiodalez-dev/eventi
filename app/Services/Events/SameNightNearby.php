<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\City;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Support\EventUrl;
use Carbon\CarbonImmutable;

/**
 * Che cosa c'è già in zona nelle ore in cui un gestore vuole mettere una data.
 *
 * Serve a decidere prima di pubblicare: se a duecento metri, alla stessa ora,
 * c'è un concerto dello stesso genere, conviene saperlo mentre si sceglie la
 * sera e non dopo. Si guardano le date pubblicate degli altri — non quelle del
 * proprio locale né dell'evento che si sta modificando — che si sovrappongono
 * all'orario indicato, entro un raggio dal luogo della data. Le date
 * annullate non contano: non sottraggono pubblico a nessuno.
 */
final class SameNightNearby
{
    public const RADIUS_KM = 2.0;

    public const LIMIT = 6;

    /**
     * @return list<array{title: string, place: string, time: string, category: string, same_category: bool, distance_m: int, url: string}>
     */
    public function around(City $city, float $lat, float $lng, CarbonImmutable $start, ?CarbonImmutable $end, ?int $ownVenueId = null, ?int $categoryId = null, ?int $ignoreEventId = null): array
    {
        $end = $end !== null && $end->greaterThan($start) ? $end : $start->addHours(3);
        $query = EventOccurrenceQuery::competitionFor($city)->excludingDemo()->availableForDiscovery()
            ->overlapping($start, $end)->near($lat, $lng, self::RADIUS_KM)->orderByDistance();
        if ($ownVenueId !== null) {
            $query->excludingVenue($ownVenueId);
        }
        if ($ignoreEventId !== null) {
            $query->excludingEvent($ignoreEventId);
        }

        /*
         * Le relazioni si caricano senza scope globali: nei pannelli Filament
         * restringe `Event` e `Venue` al locale o all'organizzatore corrente, e
         * qui servono proprio quelli degli altri. La query di partenza ha già
         * escluso cestinati, bozze e locali sospesi.
         */
        $unscoped = fn ($relation) => $relation->withoutGlobalScopes();

        return $query->take(self::LIMIT)->load([
            'event' => fn ($relation) => $relation->withoutGlobalScopes()->with(['category' => $unscoped, 'venue' => $unscoped]),
            'venue' => $unscoped,
        ])
            ->map(fn (EventOccurrence $date): array => [
                'title' => (string) $date->event?->title,
                'place' => (string) $date->effectiveVenue()?->name,
                'time' => $date->starts_at->setTimezone($city->timezone)->format('H:i'),
                'category' => (string) $date->event?->category?->name,
                'same_category' => $categoryId !== null && $date->event?->category_id === $categoryId,
                'distance_m' => (int) round((float) $date->getAttribute(EventOccurrenceQuery::DISTANCE_ALIAS)),
                'url' => EventUrl::occurrence($date),
            ])->values()->all();
    }
}
