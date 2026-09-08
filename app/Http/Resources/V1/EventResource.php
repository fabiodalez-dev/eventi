<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\FollowableType;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Tag;
use App\Models\TicketTier;
use App\Services\Seo\EditorialContent;
use App\Services\Seo\PublicOffers;
use App\Services\Seo\StructuredData;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiDate;
use App\Support\TicketTiers;
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
     * L'etichetta di sponsorizzazione, quando questo evento ne ha una viva.
     *
     * Legge dalla relazione **già caricata**, se c'è: chiamare il database da
     * dentro una risorsa significa una query per ogni evento di un elenco da
     * cinquanta. Chi vuole questo dato carica `sponsorships` con la sua
     * finestra; chi non lo carica riceve `null`, che è la verità disponibile.
     *
     * @return array{advertiser: string}|null
     */
    public static function sponsored(Event $event): ?array
    {
        if (! $event->relationLoaded('sponsorships')) {
            return null;
        }

        $campagna = $event->sponsorships->first();

        return $campagna === null ? null : ['advertiser' => (string) $campagna->advertiser_name];
    }

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
            'content_details' => app(EditorialContent::class)->details($event),
            'poster' => PosterResource::toArray($event),
            'category' => $event->category === null ? null : CategoryResource::summary($event->category),
            'tags' => $event->relationLoaded('tags')
                ? $event->tags->map(static fn (Tag $tag): array => TagResource::toArray($tag))->all()
                : [],
            'venue' => $venue === null ? null : VenueResource::toArray($venue, $timezone),
            'custom_location' => $venue === null ? $event->custom_location : null,
            'organizer' => [
                ...(app(StructuredData::class)->organizer($event) ?: ['name' => null, 'url' => null]),
                'id' => $event->organizer?->is_active ? $event->organizer->id : null,
                'slug' => $event->organizer?->is_active ? $event->organizer->slug : null,
                'host_fallback' => ! $event->organizer?->is_active && blank($event->organizer_name) && empty($event->content_details['organizer_venue_id']),
            ],
            'price' => PriceResource::toArray($event),

            /*
             * **Se questo evento è sponsorizzato, e da chi.**
             *
             * Non è un dato di comodo: la pubblicità dev'essere riconoscibile
             * come tale, e un'applicazione che riceve gli eventi senza sapere
             * quali sono a pagamento non può dichiararlo. Esporlo qui è ciò
             * che permette a un client di mettere la stessa etichetta che
             * mette il sito — e non esporlo sarebbe stato un modo per far
             * finta che il problema non esista fuori dal browser.
             *
             * `null` quando non lo è, invece di `false` più un nome vuoto: è
             * un oggetto che c'è o non c'è, e un client lo verifica una volta
             * sola.
             */
            'sponsored' => self::sponsored($event),

            /*
             * Il listino dell'evento, sola lettura. Lo stato è **per fascia**:
             * è la risposta a «tutto esaurito o restano biglietti?» che
             * `status` dell'occorrenza, da solo, non sa dare.
             */
            'tiers' => TicketTiers::for($event)
                ->map(static fn (TicketTier $tier): array => TicketTierResource::toArray($tier))
                ->all(),
            'booking' => [
                'required' => (bool) $event->booking_required,
                'url' => $event->booking_url,
                'phone' => $event->booking_phone,
            ],
            'age_restriction' => $event->age_restriction,
            'language' => $event->language,
            'is_outdoor' => (bool) $event->is_outdoor,
            // Sempre una lista di `{label, url}`, vuota quando non ce ne
            // sono: un client che deve distinguere `null` da `[]` da una
            // mappa scriverebbe tre rami per dire «nessun link».
            'external_links' => $event->external_links->toArray(),
            // Scheda tecnica: coppie `{label, value}`, lista vuota quando non
            // ce ne sono — per la stessa ragione di `external_links`.
            'facts' => $event->facts->toArray(),
            'verification_status' => $event->verification_status->value,
            'url' => self::webUrl($event, $context),
            'deep_link' => self::webUrl($event, $context),
            'following' => [
                'venue' => $context->isFollowing(FollowableType::Venue, $venue === null ? null : (int) $venue->getKey()),
                'category' => $context->isFollowing(FollowableType::Category, $event->category === null ? null : (int) $event->category->getKey()),
                'event' => $context->isFollowing(FollowableType::Event, (int) $event->getKey()),
            ],
            'occurrences' => $occurrences
                ->map(static fn (EventOccurrence $occurrence): array => [
                    ...OccurrenceResource::toArray($occurrence, $context),
                    'content_details' => app(EditorialContent::class)->details($event, $occurrence),
                    'offers' => app(PublicOffers::class)->for($event, $occurrence),
                ])
                ->all(),
            'published_at' => ApiDate::instant($event->published_at, $timezone),
            'updated_at' => ApiDate::attribute($event, 'updated_at', $timezone),
        ];
    }

    public static function webUrl(Event $event, ApiContext $context): ?string
    {
        return Route::has('city.events.show')
            ? route('city.events.show', ['city' => $context->city->slug, 'slug' => $event->slug])
            : null;
    }
}
