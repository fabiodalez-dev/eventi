<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\OccurrenceStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use Illuminate\Support\Collection;

final class SocialCatalog
{
    /**
     * Le date di un giorno per il carosello. Gli eventi dimostrativi non
     * entrano nel catalogo automatico (`social:daily`, il riquadro «oggi»):
     * sui social l'avviso della scheda non c'è. Chi ne sceglie uno per
     * identificativo dallo studio lo sta chiedendo apposta.
     *
     * @return Collection<int, EventOccurrence>
     */
    public function events(City $city, string $date, ?int $venueId = null, ?int $eventId = null): Collection
    {
        $query = $eventId !== null
            ? EventOccurrenceQuery::managementFor(Event::where('city_id', $city->id)->findOrFail($eventId))->onDate($date)
            : EventOccurrenceQuery::for($city)->excludingDemo()->onDate($date);
        if ($venueId !== null) {
            $query->atVenue($venueId);
        }
        if ($eventId !== null) {
            $query->forEvent($eventId);
        }

        return $query->get()->filter(fn (EventOccurrence $date) => $date->status === OccurrenceStatus::Scheduled)->values();
    }
}
