<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Support\DateFormatter;
use App\Support\Poster;
use Illuminate\Support\Collection;

/**
 * I dati strutturati JSON-LD di §12.2: `Event`, `Place`, `Organization`,
 * `BreadcrumbList`, `WebSite` con `SearchAction`.
 *
 * **Un nodo `Event` per ogni occorrenza pubblicata** (§11.5), non uno per
 * evento: per un motore di ricerca una serata è una data, e un ciclo di dieci
 * appuntamenti che si dichiarasse come un solo evento verrebbe mostrato con
 * una data sola. Ogni nodo porta gli orari della propria data, il proprio
 * stato e la propria disponibilità.
 */
final class StructuredData
{
    public function __construct(private readonly DateFormatter $formatter) {}

    /**
     * @param  Collection<int, EventOccurrence>  $occurrences
     * @return list<array<string, mixed>>
     */
    public function events(Event $event, Collection $occurrences): array
    {
        $nodes = [];

        foreach ($occurrences as $occurrence) {
            $nodes[] = $this->event($event, $occurrence);
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    public function event(Event $event, EventOccurrence $occurrence): array
    {
        $url = route('events.show', $event);
        $poster = Poster::absoluteUrl($event);

        $node = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            '@id' => $url.'#data-'.$occurrence->getKey(),
            'url' => $url,
            'name' => $event->title,
            'startDate' => $this->formatter->iso($occurrence->starts_at),
            'endDate' => $this->formatter->iso($occurrence->effective_ends_at),
            'eventStatus' => $this->status($occurrence->status),
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => $this->location($event),
            'organizer' => $this->organizer($event),
        ];

        if (filled($event->subtitle)) {
            $node['alternateName'] = $event->subtitle;
        }

        $description = $event->short_description ?? $event->description;

        if (filled($description)) {
            $node['description'] = str($description)->stripTags()->squish()->limit(500)->value();
        }

        if ($poster !== null) {
            $node['image'] = [$poster];
        }

        $offers = $this->offers($event, $occurrence, $url);

        if ($offers !== null) {
            $node['offers'] = $offers;
        }

        $performers = $this->performers($occurrence);

        if ($performers !== []) {
            $node['performer'] = $performers;
        }

        if ($event->relationLoaded('tags') && $event->tags->isNotEmpty()) {
            $node['keywords'] = $event->tags->pluck('name')->implode(', ');
        }

        if ($event->category !== null) {
            $node['eventType'] = $event->category->name;
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    public function venue(Venue $venue): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Place',
            '@id' => route('venues.show', $venue).'#place',
            'url' => route('venues.show', $venue),
            'name' => $venue->name,
            ...array_filter([
                'description' => $venue->short_description,
                'telephone' => $venue->phone,
                'email' => $venue->email,
                'sameAs' => $this->socials($venue),
            ], static fn (mixed $value): bool => filled($value)),
            'address' => $this->address($venue),
            'geo' => $this->geo($venue),
        ];
    }

    /**
     * @param  array<int, array{name: string, url: string}>  $items
     * @return array<string, mixed>
     */
    public function breadcrumbs(array $items): array
    {
        $elements = [];

        foreach (array_values($items) as $position => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $position + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * `WebSite` con `SearchAction`: è ciò che permette a un motore di offrire
     * la ricerca interna del sito direttamente fra i propri risultati.
     *
     * @return array<string, mixed>
     */
    public function website(?City $city): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => config()->string('app.name'),
            'url' => url('/'),
            'inLanguage' => str_replace('_', '-', app()->getLocale()),
            'description' => $city === null
                ? __('ui.footer.about_body', ['app' => config()->string('app.name'), 'city' => ''])
                : __('ui.footer.about_body', ['app' => config()->string('app.name'), 'city' => $city->name]),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => route('search').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * `Organization`: chi pubblica il sito (§12.2).
     *
     * È il nodo a cui i motori agganciano il pannello di conoscenza — nome,
     * indirizzi social, contatto. Sta sulla pagina iniziale e in nessun'altra:
     * ripeterlo su ogni scheda non aggiungerebbe niente e allungherebbe ogni
     * risposta.
     *
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        /** @var list<string> $sameAs */
        $sameAs = config()->array('seo.organization.same_as');

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => url('/').'#organizzazione',
            'name' => config()->string('app.name'),
            'legalName' => config()->string('seo.organization.legal_name'),
            'url' => url('/'),
            'email' => config()->string('seo.organization.email'),
            'sameAs' => $sameAs,
        ], static fn (mixed $value): bool => filled($value));
    }

    /**
     * Lo stato di una data nel vocabolario di schema.org.
     *
     * "Esaurito" non è uno stato dell'evento ma della disponibilità: resta
     * `EventScheduled` e la notizia passa da `offers.availability`. "Spostato"
     * riguarda il luogo, non la data, quindi non è un `EventRescheduled`.
     */
    private function status(OccurrenceStatus $status): string
    {
        return match ($status) {
            OccurrenceStatus::Cancelled => 'https://schema.org/EventCancelled',
            OccurrenceStatus::Postponed => 'https://schema.org/EventPostponed',
            OccurrenceStatus::Scheduled, OccurrenceStatus::SoldOut, OccurrenceStatus::Moved => 'https://schema.org/EventScheduled',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function location(Event $event): array
    {
        $venue = $event->venue;

        if ($venue !== null) {
            return [
                '@type' => 'Place',
                'name' => $venue->name,
                'url' => route('venues.show', $venue),
                'address' => $this->address($venue),
                'geo' => $this->geo($venue),
            ];
        }

        $custom = is_array($event->custom_location) ? $event->custom_location : [];

        return array_filter([
            '@type' => 'Place',
            'name' => is_string($custom['name'] ?? null) ? $custom['name'] : $event->city->name,
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => is_string($custom['address'] ?? null) ? $custom['address'] : null,
                'addressLocality' => is_string($custom['municipality'] ?? null) ? $custom['municipality'] : $event->city->name,
                'addressRegion' => $event->city->province_code,
                'addressCountry' => $event->city->country_code,
            ], static fn (mixed $value): bool => filled($value)),
        ], static fn (mixed $value): bool => filled($value));
    }

    /**
     * @return array<string, mixed>
     */
    private function address(Venue $venue): array
    {
        return array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $venue->address,
            'addressLocality' => $venue->municipality,
            'postalCode' => $venue->postal_code,
            'addressRegion' => $venue->province_code,
            'addressCountry' => $venue->city->country_code,
        ], static fn (mixed $value): bool => filled($value));
    }

    /**
     * @return array<string, mixed>
     */
    private function geo(Venue $venue): array
    {
        return [
            '@type' => 'GeoCoordinates',
            'latitude' => (float) $venue->lat,
            'longitude' => (float) $venue->lng,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizer(Event $event): array
    {
        if (filled($event->organizer_name)) {
            return array_filter([
                '@type' => 'Organization',
                'name' => $event->organizer_name,
                'url' => $event->organizer_url,
            ], static fn (mixed $value): bool => filled($value));
        }

        $venue = $event->venue;

        if ($venue !== null) {
            return array_filter([
                '@type' => 'Organization',
                'name' => $venue->name,
                'url' => $venue->website ?? route('venues.show', $venue),
            ], static fn (mixed $value): bool => filled($value));
        }

        return [
            '@type' => 'Organization',
            'name' => config()->string('app.name'),
            'url' => url('/'),
        ];
    }

    /**
     * Il prezzo di un'occorrenza può essere sovrascritto sulla singola data
     * (`price_override`): l'offerta dichiarata è quella della data, non quella
     * generica dell'evento.
     *
     * @return array<string, mixed>|null
     */
    private function offers(Event $event, EventOccurrence $occurrence, string $url): ?array
    {
        $override = is_array($occurrence->price_override) ? $occurrence->price_override : [];

        $type = isset($override['price_type']) && is_string($override['price_type'])
            ? PriceType::tryFrom($override['price_type']) ?? $event->price_type
            : $event->price_type;

        if ($type === PriceType::Unknown) {
            return null;
        }

        $min = $override['price_min'] ?? $event->price_min;
        $price = $type === PriceType::Free ? 0.0 : (is_numeric($min) ? (float) $min : null);

        if ($price === null) {
            return null;
        }

        return array_filter([
            '@type' => 'Offer',
            'price' => number_format($price, 2, '.', ''),
            'priceCurrency' => $event->currency !== '' ? $event->currency : 'EUR',
            'url' => $event->ticket_url ?? $url,
            'availability' => $occurrence->status === OccurrenceStatus::SoldOut
                ? 'https://schema.org/SoldOut'
                : 'https://schema.org/InStock',
            'validFrom' => $event->published_at === null ? null : $this->formatter->iso($event->published_at),
        ], static fn (mixed $value): bool => filled($value));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function performers(EventOccurrence $occurrence): array
    {
        if (! $occurrence->relationLoaded('lineups')) {
            return [];
        }

        $performers = [];

        foreach ($occurrence->lineups as $lineup) {
            $performers[] = array_filter([
                '@type' => 'PerformingGroup',
                'name' => $lineup->name,
                'url' => $lineup->url,
            ], static fn (mixed $value): bool => filled($value));
        }

        return $performers;
    }

    /**
     * @return list<string>
     */
    private function socials(Venue $venue): array
    {
        $socials = [];

        foreach (is_array($venue->socials) ? $venue->socials : [] as $url) {
            if (is_string($url) && $url !== '') {
                $socials[] = $url;
            }
        }

        if (filled($venue->website)) {
            $socials[] = (string) $venue->website;
        }

        return $socials;
    }
}
