<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\AttendanceMode;
use App\Enums\OccurrenceStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Support\DateFormatter;
use App\Support\Poster;
use App\Support\SafeUrl;
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
        $event = clone $event;
        $event->setRelation('venue', $occurrence->effectiveVenue());
        $url = route('events.show', $event);
        $poster = Poster::absoluteUrl($event);
        $details = app(EditorialContent::class)->details($event);
        $mode = AttendanceMode::tryFrom($details['attendance_mode'] ?? '') ?? AttendanceMode::Offline;

        $node = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            '@id' => $url.'#data-'.$occurrence->getKey(),
            'url' => $url,
            'name' => $event->title,
            'startDate' => $occurrence->is_all_day
                ? $occurrence->starts_at->copy()->timezone($event->city->timezone)->toDateString()
                : $this->formatter->iso($occurrence->starts_at),
            'eventStatus' => $this->status($occurrence->status),
            'eventAttendanceMode' => $mode->schema(),
            'location' => $this->location($event),
            'organizer' => $this->organizer($event),
        ];
        $online = SafeUrl::href($details['online_url'] ?? null);
        if ($node['organizer'] === []) {
            unset($node['organizer']);
        }
        if ($mode !== AttendanceMode::Offline && $online !== null) {
            $virtual = ['@type' => 'VirtualLocation', 'url' => $online];
            $node['location'] = $mode === AttendanceMode::Online ? $virtual : [$node['location'], $virtual];
        }

        // A calculated duration is useful for search, but is not a confirmed ending time.
        if ($occurrence->ends_at !== null) {
            $node['endDate'] = $occurrence->is_all_day
                ? $occurrence->ends_at->copy()->timezone($event->city->timezone)->toDateString()
                : $this->formatter->iso($occurrence->ends_at);
        }
        if ($occurrence->doors_at !== null) {
            $node['doorTime'] = $this->formatter->iso($occurrence->doors_at);
        }
        if (filled($event->language)) {
            $node['inLanguage'] = $event->language;
        }

        if (filled($event->subtitle)) {
            $node['alternateName'] = $event->subtitle;
        }

        $description = $event->short_description ?? $event->description;

        if (filled($description)) {
            $node['description'] = str($description)->stripTags()->squish()->value();
        }

        if ($poster !== null) {
            $node['image'] = [$poster];
        }

        $offers = app(PublicOffers::class)->for($event, $occurrence);

        if ($offers !== []) {
            $node['offers'] = count($offers) === 1 ? $offers[0] : $offers;
        }

        if ($occurrence->previous_starts_at !== null) {
            $node['previousStartDate'] = $this->formatter->iso($occurrence->previous_starts_at);
            if (in_array($occurrence->status, [OccurrenceStatus::Scheduled, OccurrenceStatus::Moved, OccurrenceStatus::SoldOut], true)) {
                $node['eventStatus'] = 'https://schema.org/EventRescheduled';
            }
        }

        $performers = $this->performers($occurrence);

        if ($performers !== []) {
            $node['performer'] = $performers;
        }

        if ($event->relationLoaded('tags') && $event->tags->isNotEmpty()) {
            $node['keywords'] = $event->tags->pluck('name')->implode(', ');
        }

        if ($event->category !== null) {
            $node['about'] = ['@type' => 'Thing', 'name' => $event->category->name,
                'url' => route('events.category', $event->category)];
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
                'image' => $venue->getFirstMediaUrl('cover'),
            ], static fn (mixed $value): bool => filled($value)),
            'address' => $this->address($venue),
            'geo' => $this->geo($venue),
        ];
    }

    /**
     * Describe only the links actually visible on this page, not hidden results.
     *
     * @param  list<array{name: string, url: string}>  $items
     * @return array<string, mixed>
     */
    public function collection(string $name, string $url, array $items): array
    {
        return [
            '@context' => 'https://schema.org', '@type' => 'CollectionPage',
            '@id' => $url.'#page', 'url' => $url, 'name' => $name,
            'inLanguage' => str_replace('_', '-', app()->getLocale()),
            'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($items),
                'itemListElement' => array_map(static fn (array $item, int $index): array => [
                    '@type' => 'ListItem', 'position' => $index + 1,
                    'name' => $item['name'], 'url' => $item['url'],
                ], $items, array_keys($items))],
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
     * Descrive la ricerca interna. Non promette il sitelinks search box,
     * ritirato da Google: il vocabolario Schema.org rimane valido.
     *
     * @return array<string, mixed>
     */
    public function website(?City $city): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => url('/').'#website',
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
    public function organizer(Event $event): array
    {
        if ($event->organizer?->is_active) {
            return ['@type' => 'Organization', '@id' => route('organizers.show', $event->organizer).'#organizer',
                'name' => $event->organizer->name, 'url' => route('organizers.show', $event->organizer)];
        }
        $registered = $event->content_details['organizer_venue_id'] ?? null;
        $organizer = $registered === null ? null : Venue::query()->approved()->whereKey($registered)->first();
        if ($organizer !== null) {
            return ['@type' => 'Organization', 'name' => $organizer->name, 'url' => route('venues.show', $organizer)];
        }
        if (filled($event->organizer_name)) {
            return array_filter([
                '@type' => ($event->content_details['organizer_type'] ?? null) === 'Person' ? 'Person' : 'Organization',
                'name' => $event->organizer_name,
                'url' => SafeUrl::href($event->organizer_url),
            ], static fn (mixed $value): bool => filled($value));
        }

        $venue = $event->venue;

        if ($venue !== null) {
            return array_filter([
                '@type' => 'Organization',
                'name' => $venue->name,
                /* Il sito del locale lo scrive chi lo gestisce: passa da
                   `SafeUrl` come ovunque, e se lo schema non e' http(s) si
                   ricade sulla scheda qui sul sito — che e' comunque
                   l'indirizzo giusto per quel locale. */
                'url' => SafeUrl::href($venue->website) ?? route('venues.show', $venue),
            ], static fn (mixed $value): bool => filled($value));
        }

        return [];
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
                'url' => SafeUrl::href($lineup->url),
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
            if (is_string($url) && SafeUrl::href($url) !== null) {
                $socials[] = SafeUrl::href($url);
            }
        }

        /* `sameAs` dichiara «questo locale e' anche quello»: un indirizzo con
           uno schema che non sia http(s) non identifica niente, e pubblicarlo
           significa mettere in un dato strutturato un valore che nessuno ha
           controllato. */
        $sito = SafeUrl::href($venue->website);

        if ($sito !== null) {
            $socials[] = $sito;
        }

        return $socials;
    }
}
