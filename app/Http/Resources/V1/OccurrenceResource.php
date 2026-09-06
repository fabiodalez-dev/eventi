<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\ApiInclude;
use App\Enums\FollowableType;
use App\Models\EventOccurrence;
use App\Models\Lineup;
use App\Models\Tag;
use App\Models\TicketTier;
use App\Queries\EventOccurrenceQuery;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiDate;
use App\Support\MapLinks;
use App\Support\TicketTiers;
use DateTimeInterface;

/**
 * L'unità di risposta dell'API: **un'occorrenza, non un evento** (§13.2).
 *
 * Un evento con dieci date è dieci elementi in lista, perché è la data che si
 * mette in agenda. I campi sono quelli elencati da §13.2, nell'ordine in cui
 * li elenca.
 *
 * Due cose non compaiono mai qui:
 *
 * - `editorial_score`, che è uno strumento della redazione e non un dato
 *   pubblico: esporlo insegnerebbe a chiunque come farsi spingere in alto;
 * - `is_saved` quando il chiamante non è autenticato (§15.8), perché `false`
 *   direbbe "non salvato" là dove la verità è "non lo so".
 */
final class OccurrenceResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(EventOccurrence $occurrence, ApiContext $context): array
    {
        $event = $occurrence->event;
        $venue = $event->venue;
        $timezone = $context->timezone;

        $payload = [
            'occurrence_id' => (int) $occurrence->getKey(),
            'date_url' => route('events.occurrence', ['slug' => $event->slug, 'occurrence' => $occurrence->id]),
            'previous_starts_at' => ApiDate::instant($occurrence->previous_starts_at, $timezone),
            'event_id' => (int) $event->getKey(),
            'event_slug' => (string) $event->slug,
            'starts_at' => ApiDate::instant($occurrence->starts_at, $timezone),
            'ends_at' => ApiDate::instant($occurrence->ends_at, $timezone),
            'effective_ends_at' => ApiDate::instant($occurrence->effective_ends_at, $timezone),

            /* `ends_at` assente significa che la fine è dedotta dalla durata
               della categoria (§8.3): il client deve poterlo dire, perché
               "finisce alle 23" e "finirà verso le 23" non sono la stessa cosa. */
            'ends_at_estimated' => $occurrence->ends_at === null,

            'doors_at' => ApiDate::instant($occurrence->doors_at, $timezone),
            'is_all_day' => (bool) $occurrence->is_all_day,
            'business_date' => ApiDate::day($occurrence->business_date),
            'status' => $occurrence->status->value,
            'status_note' => $occurrence->status_note,

            /* L'etichetta di richiamo scritta dal locale («ULTIMI POSTI»):
               testo già pronto da mostrare, non un codice da interpretare. */
            'highlight' => $occurrence->highlight,

            /* Capienza e posti rimasti. `capacity` nullo significa "quella del
               locale": è là che il client la trova, non qui duplicata. */
            'capacity' => $occurrence->capacity,
            'capacity_left' => $occurrence->capacity_left,
            'booking_enabled' => (bool) ($occurrence->booking_enabled && $venue?->ticketing_enabled),
            'title' => (string) $event->title,
            'subtitle' => $event->subtitle,
            'short_description' => $event->short_description,
            'poster' => PosterResource::toArray($event),
            'venue' => $venue === null
                ? null
                : ($context->wants(ApiInclude::Venue)
                    ? VenueResource::toArray($venue, $timezone)
                    : VenueResource::summary($venue)),
            'custom_location' => $venue === null ? $event->custom_location : null,
            'category' => $event->category === null ? null : CategoryResource::summary($event->category),
            'tags' => self::tags($occurrence, $context),
            'price' => PriceResource::toArray($event, $occurrence),
            'is_outdoor' => (bool) $event->is_outdoor,
            'sponsored' => EventResource::sponsored($event),
            'url' => EventResource::webUrl($event, $context),
            'deep_link' => EventResource::webUrl($event, $context),
            'actions' => self::actions($occurrence, $context),
            'content_updated_at' => self::contentUpdatedAt($occurrence, $timezone),
            'updated_at' => ApiDate::attribute($occurrence, 'updated_at', $timezone),
        ];

        $distance = $occurrence->getAttribute(EventOccurrenceQuery::DISTANCE_ALIAS);

        if ($distance !== null) {
            $payload['distance_m'] = (int) round((float) $distance);
        }

        /*
         * Il listino arriva **già risolto** per questa data: se la serata ne
         * ha uno proprio è quello, altrimenti è quello dell'evento. La regola
         * sta in un posto solo, e il client non deve conoscerla.
         */
        if ($context->wants(ApiInclude::Tiers)) {
            $payload['tiers'] = TicketTiers::for($event, $occurrence)
                ->map(static fn (TicketTier $tier): array => TicketTierResource::toArray($tier))
                ->all();
        }

        if ($context->wants(ApiInclude::Lineup)) {
            $payload['lineup'] = $occurrence->relationLoaded('lineups')
                ? $occurrence->lineups->map(
                    static fn (Lineup $member): array => LineupResource::toArray($member, $timezone),
                )->all()
                : [];
        }

        $saved = $context->isSaved((int) $occurrence->getKey());

        if ($saved !== null) {
            $payload['is_saved'] = $saved;
            $payload['following'] = [
                'venue' => $context->isFollowing(FollowableType::Venue, $venue === null ? null : (int) $venue->getKey()),
                'category' => $context->isFollowing(FollowableType::Category, $event->category === null ? null : (int) $event->category->getKey()),
                'event' => $context->isFollowing(FollowableType::Event, (int) $event->getKey()),
            ];
        }

        return $payload;
    }

    /** @return array<string, string|null> */
    private static function actions(EventOccurrence $occurrence, ApiContext $context): array
    {
        $event = $occurrence->event;
        $city = $context->city;
        $parameters = ['city' => $city->slug, 'slug' => $event->slug, 'occurrence' => $occurrence->getKey()];

        $venue = $event->venue;

        return [
            'web' => EventResource::webUrl($event, $context),
            'calendar' => route('city.events.calendar', $parameters),
            'poster_pdf' => route('city.events.poster', $parameters),
            'google_maps' => $venue === null ? null : MapLinks::google($venue),
            'apple_maps' => $venue === null ? null : MapLinks::apple($venue),
            'openstreetmap' => $venue === null ? null : MapLinks::openStreetMap($venue),
        ];
    }

    private static function contentUpdatedAt(EventOccurrence $occurrence, string $timezone): ?string
    {
        $latest = null;

        foreach ([$occurrence, $occurrence->event, $occurrence->event->venue, $occurrence->event->category] as $model) {
            $date = $model?->getAttribute('updated_at');

            if ($date instanceof DateTimeInterface
                && ($latest === null || $date->getTimestamp() > $latest->getTimestamp())) {
                $latest = $date;
            }
        }

        return ApiDate::instant($latest, $timezone);
    }

    /**
     * I tag viaggiano solo con `include=tags`: sono una seconda tabella per
     * ogni evento, e una lista di cinquanta occorrenze non deve pagarli
     * quando nessuno li ha chiesti.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function tags(EventOccurrence $occurrence, ApiContext $context): ?array
    {
        if (! $context->wants(ApiInclude::Tags)) {
            return null;
        }

        $event = $occurrence->event;

        if (! $event->relationLoaded('tags')) {
            return [];
        }

        return $event->tags->map(static fn (Tag $tag): array => TagResource::toArray($tag))->all();
    }
}
