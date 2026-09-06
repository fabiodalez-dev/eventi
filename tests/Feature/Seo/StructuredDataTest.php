<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Models\Lineup;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

/**
 * I dati strutturati di §12.2, verificati **sul documento servito** e non
 * sull'array che li ha generati: quello che conta è che un motore di ricerca
 * trovi JSON valido, e fra l'array e la pagina c'è una codifica che può
 * romperlo.
 */

/**
 * Estrae e decodifica ogni blocco `application/ld+json` della risposta.
 *
 * @return list<array<string, mixed>>
 */
function jsonLdOf(string $html): array
{
    preg_match_all(
        '#<script type="application/ld\+json">(.*?)</script>#s',
        $html,
        $matches,
    );

    $nodes = [];

    foreach ($matches[1] as $raw) {
        $decoded = json_decode($raw, true);

        expect($decoded)->toBeArray("blocco JSON-LD non decodificabile: {$raw}");

        $nodes[] = $decoded;
    }

    return $nodes;
}

/**
 * @param  list<array<string, mixed>>  $nodes
 * @return list<array<string, mixed>>
 */
function nodesOfType(array $nodes, string $type): array
{
    return array_values(array_filter($nodes, static fn (array $node): bool => ($node['@type'] ?? null) === $type));
}

it('emette un nodo Event per ogni occorrenza pubblicata, con i campi obbligatori di schema.org', function (): void {
    Storage::fake('public');

    $city = testCity();
    $category = testCategory();

    $prima = occurrenceAtLocal($city, $category, '2026-09-12 21:30', '2026-09-12 23:30', event: [
        'title' => 'Rassegna d\'autunno',
        'price_type' => PriceType::Ticket,
        'price_min' => 8,
    ]);

    $event = $prima->event;

    occurrenceAtLocal($city, $category, '2026-09-19 21:30', '2026-09-19 23:30', occurrence: [
        'event_id' => $event->getKey(),
    ]);

    Lineup::factory()->create(['occurrence_id' => $prima->getKey(), 'name' => 'Trio Naviglio']);

    $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg()))->toMediaCollection('poster');

    freezeLocal($city, '2026-09-01 12:00');

    $html = $this->get(route('events.show', $event))->assertOk()->getContent();
    expect(nodesOfType(jsonLdOf($html), 'CollectionPage'))->toHaveCount(1);
    $events = [];
    foreach ($event->occurrences as $date) {
        $page = $this->get(route('events.occurrence', ['slug' => $event->slug, 'occurrence' => $date->id]))->assertOk()->getContent();
        $events = array_merge($events, nodesOfType(jsonLdOf($page), 'Event'));
    }

    // Due date pubblicate, due nodi: per un motore una serata è una data.
    expect($events)->toHaveCount(2);

    foreach ($events as $node) {
        // I tre campi che schema.org dichiara obbligatori per Event.
        expect($node)->toHaveKeys(['@context', '@type', 'name', 'startDate', 'location'])
            ->and($node['@context'])->toBe('https://schema.org')
            ->and($node['name'])->toBe('Rassegna d\'autunno');

        // La data è un istante ISO 8601 con l'offset, come §13 impone ovunque.
        expect($node['startDate'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/')
            ->and($node['endDate'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');

        // Quelli che §11.5 elenca esplicitamente.
        expect($node)->toHaveKeys(['eventStatus', 'eventAttendanceMode', 'organizer', 'image', 'offers', 'url'])
            ->and($node['eventStatus'])->toBe('https://schema.org/EventScheduled')
            ->and($node['eventAttendanceMode'])->toBe('https://schema.org/OfflineEventAttendanceMode');

        // `location: Place` con indirizzo e coordinate.
        expect($node['location']['@type'])->toBe('Place')
            ->and($node['location']['address']['@type'])->toBe('PostalAddress')
            ->and($node['location']['address'])->toHaveKeys(['streetAddress', 'addressLocality', 'addressCountry'])
            ->and($node['location']['geo']['@type'])->toBe('GeoCoordinates')
            ->and($node['location']['geo']['latitude'])->toBeFloat()
            ->and($node['location']['geo']['longitude'])->toBeFloat();

        // `offers` con prezzo, valuta e disponibilità.
        expect($node['offers']['@type'])->toBe('Offer')
            ->and($node['offers']['price'])->toBe('8.00')
            ->and($node['offers']['priceCurrency'])->toBe('EUR')
            ->and($node['offers']['availability'])->toBe('https://schema.org/InStock');

        expect($node['image'][0])->toStartWith('http');
    }

    // La lineup della prima data diventa `performer`, e solo di quella.
    expect($events[0]['performer'][0]['name'])->toBe('Trio Naviglio');

    // I due nodi hanno identificativi distinti: sono due date, non due copie.
    expect($events[0]['@id'])->not->toBe($events[1]['@id']);
});

it('dichiara le briciole di pane sulla scheda di un evento', function (): void {
    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-12 21:30')->event;

    freezeLocal($city, '2026-09-01 12:00');

    $nodes = jsonLdOf($this->get(route('events.show', $event))->assertOk()->getContent());
    $breadcrumbs = nodesOfType($nodes, 'BreadcrumbList');

    expect($breadcrumbs)->toHaveCount(1)
        ->and($breadcrumbs[0]['itemListElement'])->toHaveCount(3)
        ->and($breadcrumbs[0]['itemListElement'][0]['position'])->toBe(1)
        ->and($breadcrumbs[0]['itemListElement'][2]['name'])->toBe($event->title);
});

it('dichiara Place sulla scheda di un locale', function (): void {
    $city = testCity();
    $category = testCategory();
    $venue = occurrenceAtLocal($city, $category, '2026-09-12 21:30')->event->venue;

    freezeLocal($city, '2026-09-01 12:00');

    $nodes = jsonLdOf($this->get(route('venues.show', $venue))->assertOk()->getContent());
    $places = nodesOfType($nodes, 'Place');

    expect($places)->toHaveCount(1)
        ->and($places[0]['name'])->toBe($venue->name)
        ->and($places[0]['address']['@type'])->toBe('PostalAddress')
        ->and($places[0]['geo']['latitude'])->toBeFloat();
});

it('dichiara WebSite con SearchAction e Organization sulla pagina iniziale', function (): void {
    $city = testCity();
    $category = testCategory();
    occurrenceAtLocal($city, $category, '2026-09-12 21:30');

    freezeLocal($city, '2026-09-01 12:00');

    $nodes = jsonLdOf($this->get('/')->assertOk()->getContent());

    $website = nodesOfType($nodes, 'WebSite');
    $organization = nodesOfType($nodes, 'Organization');

    expect($website)->toHaveCount(1)
        ->and($website[0]['potentialAction']['@type'])->toBe('SearchAction')
        ->and($website[0]['potentialAction']['target']['urlTemplate'])->toContain('{search_term_string}')
        ->and($website[0]['potentialAction']['query-input'])->toBe('required name=search_term_string');

    expect($organization)->toHaveCount(1)
        ->and($organization[0]['name'])->toBe(config('app.name'))
        ->and($organization[0]['url'])->toBe(url('/'));
});

it('dichiara annullato cio che e annullato', function (): void {
    $city = testCity();
    $category = testCategory();

    $occurrence = occurrenceAtLocal($city, $category, '2026-09-12 21:30', occurrence: [
        'status' => OccurrenceStatus::Cancelled,
    ]);

    freezeLocal($city, '2026-09-01 12:00');

    $nodes = jsonLdOf($this->get(route('events.show', $occurrence->event))->assertOk()->getContent());

    expect(nodesOfType($nodes, 'Event')[0]['eventStatus'])->toBe('https://schema.org/EventCancelled');
});
