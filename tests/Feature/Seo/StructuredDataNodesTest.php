<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Lineup;
use App\Models\Tag;
use App\Models\Venue;
use App\Services\Seo\StructuredData;
use Carbon\Carbon;

/**
 * `App\Services\Seo\StructuredData` visto dal lato dei **nodi**, non del
 * documento: `StructuredDataTest` verifica che la pagina serva JSON valido,
 * qui si verifica che dentro quel JSON ci sia la cosa giusta nei casi che la
 * pagina di prova non attraversa mai — prezzo libero, prezzo sconosciuto,
 * prezzo riscritto sulla singola data, luogo senza locale, organizzatore
 * dichiarato a mano.
 *
 * Sono i rami in cui un errore non si vede: il documento resta valido e il
 * motore di ricerca mostra un prezzo che non esiste.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->structured = app(StructuredData::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('dichiara zero come prezzo di un evento gratuito, non l\'assenza di prezzo', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: [
        'price_type' => PriceType::Free,
        'price_min' => null,
    ]);

    $node = $this->structured->event($occurrence->event, $occurrence);

    /* Un `Offer` con prezzo 0 è ciò che schema.org chiede per dire "gratis":
       omettere l'offerta significherebbe "non lo so", che è un'altra cosa. */
    expect($node['offers']['price'])->toBe('0.00')
        ->and($node['offers']['priceCurrency'])->toBe('EUR')
        ->and($node['offers']['availability'])->toBe('https://schema.org/InStock');
});

it('non inventa un prezzo quando il prezzo non si conosce', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: [
        'price_type' => PriceType::Unknown,
        'price_min' => null,
    ]);

    expect($this->structured->event($occurrence->event, $occurrence))->not->toHaveKey('offers');
});

it('tace sul prezzo di un evento a biglietto di cui non si sa quanto costi', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: [
        'price_type' => PriceType::Ticket,
        'price_min' => null,
    ]);

    /* «Biglietto» senza cifra non è un'offerta: dichiararla a zero direbbe
       gratis, e dichiararla vuota farebbe scartare l'intero nodo. */
    expect($this->structured->event($occurrence->event, $occurrence))->not->toHaveKey('offers');
});

it('preferisce il prezzo scritto sulla singola data a quello generico dell\'evento', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', occurrence: [
        'price_override' => ['price_type' => PriceType::Ticket->value, 'price_min' => 15],
    ], event: [
        'price_type' => PriceType::Free,
        'price_min' => null,
    ]);

    expect($this->structured->event($occurrence->event, $occurrence)['offers']['price'])->toBe('15.00');
});

it('una data esaurita resta programmata: la notizia sta nella disponibilità', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', occurrence: [
        'status' => OccurrenceStatus::SoldOut,
    ], event: [
        'price_type' => PriceType::Ticket,
        'price_min' => 10,
    ]);

    $node = $this->structured->event($occurrence->event, $occurrence);

    expect($node['eventStatus'])->toBe('https://schema.org/EventScheduled')
        ->and($node['offers']['availability'])->toBe('https://schema.org/SoldOut');
});

it('traduce rinviato e spostato nel vocabolario di schema.org', function (): void {
    $rinviata = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', occurrence: [
        'status' => OccurrenceStatus::Postponed,
    ]);

    $spostata = occurrenceAtLocal($this->city, $this->category, '2026-09-13 21:00', occurrence: [
        'status' => OccurrenceStatus::Moved,
    ]);

    expect($this->structured->event($rinviata->event, $rinviata)['eventStatus'])
        ->toBe('https://schema.org/EventPostponed')
        /* «Spostato» qui riguarda il luogo, non la data: per schema.org
           l'appuntamento è ancora quello previsto. */
        ->and($this->structured->event($spostata->event, $spostata)['eventStatus'])
        ->toBe('https://schema.org/EventScheduled');
});

it('descrive il luogo di un evento che non si tiene in un locale registrato', function (): void {
    $event = Event::factory()->withoutVenue()->published()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
    ]);

    $occurrence = EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => localInstant($this->city, '2026-09-12 18:00')->utc(),
        'ends_at' => null,
    ]);

    $location = $this->structured->event($event->fresh(), $occurrence)['location'];

    expect($location['@type'])->toBe('Place')
        ->and($location['name'])->toBe('Prato della Valle')
        ->and($location['address']['streetAddress'])->toBe('Prato della Valle, Padova')
        ->and($location['address']['addressLocality'])->toBe($this->city->name)
        ->and($location['address']['addressRegion'])->toBe($this->city->province_code)
        /* Senza locale non ci sono coordinate certificate: meglio nessuna
           `geo` di una coppia di numeri inventata. */
        ->and($location)->not->toHaveKey('geo');
});

it('omette il nome della sede quando non è conosciuto', function (): void {
    $event = Event::factory()->published()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
        'venue_id' => null,
        'custom_location' => ['address' => 'Sotto il tendone'],
    ]);

    $occurrence = EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => localInstant($this->city, '2026-09-12 18:00')->utc(),
    ]);

    expect($this->structured->event($event->fresh(), $occurrence)['location'])->not->toHaveKey('name');
});

it('sceglie l\'organizzatore in tre gradini: chi è dichiarato, il locale, il sito', function (): void {
    $conNome = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: [
        'organizer_name' => 'Associazione Corale Patavina',
        'organizer_url' => 'https://corale.example.test',
    ]);

    expect($this->structured->event($conNome->event, $conNome)['organizer'])->toBe([
        '@type' => 'Organization',
        'name' => 'Associazione Corale Patavina',
        'url' => 'https://corale.example.test',
    ]);

    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'name' => 'Circolo Aurora',
        'website' => 'https://aurora.example.test',
    ]);

    $delLocale = occurrenceAtLocal($this->city, $this->category, '2026-09-13 21:00', event: [
        'organizer_name' => null,
    ], venue: $venue);

    expect($this->structured->event($delLocale->event, $delLocale)['organizer'])->toBe([
        '@type' => 'Organization',
        'name' => 'Circolo Aurora',
        'url' => 'https://aurora.example.test',
    ]);

    $event = Event::factory()->published()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
        'venue_id' => null,
        'organizer_name' => null,
        'custom_location' => ['name' => 'Piazza dei Signori'],
    ]);

    $occurrence = EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => localInstant($this->city, '2026-09-14 18:00')->utc(),
    ]);

    expect($this->structured->event($event->fresh(), $occurrence))->not->toHaveKey('organizer');
});

it('ripulisce la descrizione dal markup senza troncare il contenuto', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: [
        'short_description' => null,
        'description' => '<p>Una <strong>serata</strong>   con    tanti   spazi</p>'.str_repeat(' parola', 200),
    ]);

    $description = $this->structured->event($occurrence->event, $occurrence)['description'];

    expect($description)->not->toContain('<')
        ->and($description)->toStartWith('Una serata con tanti spazi')
        ->and(mb_strlen($description))->toBeGreaterThan(500)
        ->and($description)->toEndWith('parola');
});

it('preferisce il sommario alla descrizione lunga, e non scrive nulla se non c\'è né l\'uno né l\'altra', function (): void {
    $conSommario = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: [
        'short_description' => 'Tre quartetti in una sera sola.',
        'description' => 'Testo lungo che non deve comparire qui.',
    ]);

    expect($this->structured->event($conSommario->event, $conSommario)['description'])
        ->toBe('Tre quartetti in una sera sola.');

    $senza = occurrenceAtLocal($this->city, $this->category, '2026-09-13 21:00', event: [
        'short_description' => null,
        'description' => null,
    ]);

    expect($this->structured->event($senza->event, $senza))->not->toHaveKey('description');
});

it('porta nel nodo il sottotitolo, i tag e la categoria quando ci sono', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: [
        'subtitle' => 'Terza edizione',
    ]);

    $event = $occurrence->event;
    $event->tags()->attach([
        Tag::factory()->create(['name' => 'Jazz'])->getKey(),
        Tag::factory()->create(['name' => 'Ingresso libero'])->getKey(),
    ]);

    $node = $this->structured->event($event->load('tags', 'category'), $occurrence);

    expect($node['alternateName'])->toBe('Terza edizione')
        ->and($node['keywords'])->toBe('Jazz, Ingresso libero')
        ->and($node)->not->toHaveKey('eventType')
        ->and($node['about']['name'])->toBe($this->category->name);
});

it('non dichiara interpreti che non ha caricato invece di inventare una lista vuota', function (): void {
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00');

    Lineup::factory()->create(['occurrence_id' => $occurrence->getKey(), 'name' => 'Trio Naviglio']);

    /* Senza la relazione caricata il nodo tace: leggerla qui significherebbe
       una query per ogni data di un cartellone intero. */
    expect($this->structured->event($occurrence->event, $occurrence->fresh()))->not->toHaveKey('performer');

    $node = $this->structured->event($occurrence->event, $occurrence->fresh()?->load('lineups'));

    expect($node['performer'][0]['name'])->toBe('Trio Naviglio');
});

it('raccoglie in sameAs i profili social del locale e il suo sito', function (): void {
    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'website' => 'https://aurora.example.test',
        'socials' => ['facebook' => 'https://facebook.example.test/aurora', 'instagram' => ''],
        'phone' => null,
        'email' => null,
    ]);

    $node = $this->structured->venue($venue);

    expect($node['sameAs'])->toBe([
        'https://facebook.example.test/aurora',
        'https://aurora.example.test',
    ])
        /* Telefono ed email assenti non diventano chiavi vuote: un campo
           vuoto in JSON-LD è un dato sbagliato, non un dato mancante. */
        ->and($node)->not->toHaveKey('telephone')
        ->and($node)->not->toHaveKey('email')
        ->and($node['geo']['latitude'])->toBeFloat();
});

it('numera le briciole di pane da uno, nell\'ordine in cui gliele si danno', function (): void {
    $node = $this->structured->breadcrumbs([
        ['name' => 'Home', 'url' => 'https://example.test/'],
        ['name' => 'Eventi', 'url' => 'https://example.test/eventi'],
        ['name' => 'Concerto', 'url' => 'https://example.test/eventi/concerto'],
    ]);

    expect($node['@type'])->toBe('BreadcrumbList')
        ->and($node['itemListElement'])->toHaveCount(3)
        ->and(array_column($node['itemListElement'], 'position'))->toBe([1, 2, 3])
        ->and($node['itemListElement'][2]['item'])->toBe('https://example.test/eventi/concerto')
        ->and($this->structured->breadcrumbs([])['itemListElement'])->toBe([]);
});

it('genera un nodo per ogni data di un ciclo, ognuno con indirizzo e identificativo propri', function (): void {
    $prima = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00');
    $event = $prima->event;

    $seconda = EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => localInstant($this->city, '2026-09-19 21:00')->utc(),
    ]);

    $nodes = $this->structured->events($event, collect([$prima, $seconda]));

    expect($nodes)->toHaveCount(2)
        ->and($nodes[0]['url'])->not->toBe($nodes[1]['url'])
        ->and($nodes[0]['@id'])->toBe($nodes[0]['url'].'#event')
        ->and($nodes[1]['@id'])->toBe($nodes[1]['url'].'#event')
        ->and($this->structured->events($event, collect()))->toBe([]);
});
