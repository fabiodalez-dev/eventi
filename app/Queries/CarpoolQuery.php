<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\OccurrenceStatus;
use App\Enums\RideLeg;
use App\Enums\RideStatus;
use App\Models\EventOccurrence;
use App\Models\RideOffer;
use Carbon\CarbonImmutable;

final class CarpoolQuery
{
    public function visible(EventOccurrence $date): bool
    {
        $city = $date->event?->city;

        return $city !== null && ! in_array($date->status, [OccurrenceStatus::Cancelled, OccurrenceStatus::Postponed], true)
            && EventOccurrenceQuery::archiveFor($city)->identifiersQuery()->where('event_occurrences.id', $date->id)->exists();
    }

    public function departure(string $value, EventOccurrence $date): CarbonImmutable
    {
        return CarbonImmutable::parse($value, $date->event->city->timezone)->utc();
    }

    public function allowed(EventOccurrence $date, RideLeg $leg, CarbonImmutable $departure): bool
    {
        if (! $this->visible($date)) {
            return false;
        }
        $start = CarbonImmutable::instance($date->starts_at);
        $end = CarbonImmutable::instance($date->effective_ends_at);

        return $departure->greaterThan($this->now($date)) && ($leg === RideLeg::Outbound
            ? $departure->betweenIncluded($start->subDay(), $end)
            : $departure->betweenIncluded($start, $end->addDay()));
    }

    public function now(EventOccurrence $date): CarbonImmutable
    {
        // Senza evento (o senza città) non c'è un orologio locale da consultare:
        // l'istante assoluto resta lo stesso, basta non dereferenziare il vuoto.
        $city = $date->event?->city;

        return $city !== null ? EventOccurrenceQuery::archiveFor($city)->now()->utc() : CarbonImmutable::now()->utc();
    }

    public function future(RideOffer $offer): bool
    {
        return $offer->departure_at->greaterThan($this->now($offer->occurrence));
    }

    public function operational(RideOffer $offer): bool
    {
        return in_array($offer->status, [RideStatus::Open, RideStatus::Closed], true)
            && $this->future($offer) && $this->visible($offer->occurrence)
            && ! $this->changed($offer);
    }

    /** @return array<string, int|string|null> */
    public function snapshot(EventOccurrence $date): array
    {
        return ['event_id' => $date->event_id, 'title' => $date->event?->title,
            'starts_at' => $date->starts_at->toIso8601String(), 'ends_at' => $date->effective_ends_at->toIso8601String(),
            'venue_id' => $date->locationVenue()?->id, 'location' => $date->locationLabel()];
    }

    public function changed(RideOffer $offer): bool
    {
        $current = $this->snapshot($offer->occurrence);
        // Editorial title changes never invalidate a travel agreement.
        unset($current['title']);
        $old = $offer->snapshot;
        unset($old['title']);

        return $current !== $old;
    }
}
