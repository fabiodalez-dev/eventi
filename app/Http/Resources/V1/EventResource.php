<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Tag;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * La scheda editoriale di un evento: quella di `GET /v1/events/{slug}`.
 *
 * Un evento **non è una data** (§11.5): porta con sé l'elenco delle proprie
 * occorrenze future, che restano la cosa che si mette in agenda. Le date le
 * sceglie il motore temporale e arrivano già pronte da chi chiama: la risorsa
 * non interroga nulla.
 *
 * `editorial_score` non compare: è il criterio con cui la redazione ordina, e
 * pubblicarlo sarebbe pubblicare la formula.
 */
final class EventResource
{
    /**
     * @param  Collection<int, EventOccurrence>  $occurrences
     * @return array<string, mixed>
     */
    public static function toArray(Event $event, Collection $occurrences, ApiContext $context): array
    {
        $timezone = $context->timezone;
        $venue = $event->venue;

        return [
            'id' => (int) $event->getKey(),
            'slug' => (string) $event->slug,
            'title' => (string) $event->title,
            'subtitle' => $event->subtitle,
            'description' => $event->description,
            'short_description' => $event->short_description,
            'poster' => PosterResource::toArray($event),
            'category' => $event->category === null ? null : CategoryResource::summary($event->category),
            'tags' => $event->relationLoaded('tags')
                ? $event->tags->map(static fn (Tag $tag): array => TagResource::toArray($tag))->all()
                : [],
            'venue' => $venue === null ? null : VenueResource::toArray($venue, $timezone),
            'custom_location' => $venue === null ? $event->custom_location : null,
            'organizer' => [
                'name' => $event->organizer_name,
                'url' => $event->organizer_url,
            ],
            'price' => PriceResource::toArray($event),
            'booking' => [
                'required' => (bool) $event->booking_required,
                'url' => $event->booking_url,
                'phone' => $event->booking_phone,
            ],
            'age_restriction' => $event->age_restriction,
            'language' => $event->language,
            'is_outdoor' => (bool) $event->is_outdoor,
            'external_links' => $event->external_links,
            'verification_status' => $event->verification_status->value,
            'url' => Route::has('events.show') ? route('events.show', $event) : null,
            'occurrences' => $occurrences
                ->map(static fn (EventOccurrence $occurrence): array => OccurrenceResource::toArray($occurrence, $context))
                ->all(),
            'published_at' => ApiDate::instant($event->published_at, $timezone),
            'updated_at' => ApiDate::attribute($event, 'updated_at', $timezone),
        ];
    }
}
