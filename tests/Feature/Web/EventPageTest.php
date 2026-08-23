<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\LineupRole;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Models\EventOccurrence;
use App\Models\Lineup;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array<int, array<string, mixed>> */
function jsonLdNodes(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    $nodes = [];

    foreach ($matches[1] as $raw) {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            $nodes[] = $decoded;
        }
    }

    return $nodes;
}

it('elenca tutte le date future, non solo la prima', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $first = occurrenceAtLocal($city, $category, '2026-09-10 21:30:00', event: ['title' => 'Rassegna di settembre']);
    $event = $first->event;

    foreach (['2026-09-17 21:30:00', '2026-09-24 21:30:00'] as $date) {
        EventOccurrence::factory()->create([
            'event_id' => $event->getKey(),
            'starts_at' => localInstant($city, $date)->utc(),
            'ends_at' => null,
            'doors_at' => null,
        ]);
    }

    $response = $this->get('/eventi/'.$event->slug)->assertOk();

    $response->assertSee('giovedì 10 settembre')
        ->assertSee('giovedì 17 settembre')
        ->assertSee('giovedì 24 settembre');
});

it('pubblica un nodo JSON-LD per ogni occorrenza, con luogo, offerta e stato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $occurrence = occurrenceAtLocal($city, $category, '2026-09-10 21:30:00', '2026-09-10 23:30:00', event: [
        'title' => 'Concerto con biglietto',
        'price_type' => PriceType::Ticket,
        'price_min' => 8,
        'price_max' => 8,
        'organizer_name' => 'Associazione Prova',
    ]);

    $event = $occurrence->event;

    EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => localInstant($city, '2026-09-17 21:30:00')->utc(),
        'ends_at' => localInstant($city, '2026-09-17 23:30:00')->utc(),
        'doors_at' => null,
    ]);

    Lineup::factory()->create([
        'occurrence_id' => $occurrence->getKey(),
        'name' => 'Trio Acustico',
        'role' => LineupRole::Live,
    ]);

    $nodes = jsonLdNodes($this->get('/eventi/'.$event->slug)->assertOk()->getContent() ?: '');

    $events = array_values(array_filter($nodes, static fn (array $node): bool => ($node['@type'] ?? null) === 'Event'));

    expect($events)->toHaveCount(2);

    $first = $events[0];

    expect($first['startDate'])->toBe('2026-09-10T21:30:00+02:00')
        ->and($first['endDate'])->toBe('2026-09-10T23:30:00+02:00')
        ->and($first['eventStatus'])->toBe('https://schema.org/EventScheduled')
        ->and($first['eventAttendanceMode'])->toBe('https://schema.org/OfflineEventAttendanceMode')
        ->and($first['location']['@type'])->toBe('Place')
        ->and($first['location']['geo']['@type'])->toBe('GeoCoordinates')
        ->and($first['offers']['price'])->toBe('8.00')
        ->and($first['offers']['priceCurrency'])->toBe('EUR')
        ->and($first['organizer']['name'])->toBe('Associazione Prova')
        ->and($first['performer'][0]['name'])->toBe('Trio Acustico')
        ->and($first['@id'])->not->toBe($events[1]['@id']);

    expect(array_filter($nodes, static fn (array $node): bool => ($node['@type'] ?? null) === 'BreadcrumbList'))->not->toBeEmpty();
});

it('dichiara annullata una data annullata anche nei dati strutturati', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $occurrence = occurrenceAtLocal($city, $category, '2026-09-10 21:30:00', occurrence: [
        'status' => OccurrenceStatus::Cancelled,
    ]);

    $nodes = jsonLdNodes($this->get('/eventi/'.$occurrence->event->slug)->assertOk()->getContent() ?: '');
    $events = array_values(array_filter($nodes, static fn (array $node): bool => ($node['@type'] ?? null) === 'Event'));

    expect($events[0]['eventStatus'])->toBe('https://schema.org/EventCancelled');
});

it('offre un file .ics per la singola data', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $occurrence = occurrenceAtLocal($city, $category, '2026-09-10 21:30:00', '2026-09-10 23:30:00');

    $response = $this->get(route('events.calendar', [
        'slug' => $occurrence->event->slug,
        'occurrence' => $occurrence->getKey(),
    ]))->assertOk();

    $response->assertHeader('content-type', 'text/calendar; charset=utf-8');

    $body = $response->getContent() ?: '';

    expect($body)->toContain('BEGIN:VCALENDAR')
        ->toContain('DTSTART:20260910T193000Z')
        ->toContain('DTEND:20260910T213000Z');
});

it('non consegna il calendario di una data che appartiene a un altro evento', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $primo = occurrenceAtLocal($city, $category, '2026-09-10 21:30:00');
    $secondo = occurrenceAtLocal($city, $category, '2026-09-11 21:30:00');

    $this->get(route('events.calendar', [
        'slug' => $primo->event->slug,
        'occurrence' => $secondo->getKey(),
    ]))->assertNotFound();
});

it('propone eventi simili e altre date dello stesso locale, senza ripetere sé stesso', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $principale = occurrenceAtLocal($city, $category, '2026-09-10 21:30:00', event: ['title' => 'Serata principale']);
    $venue = $principale->event->venue;

    occurrenceAtLocal($city, $category, '2026-09-12 21:30:00', venue: $venue, event: ['title' => 'Altra serata nello stesso locale']);

    $response = $this->get('/eventi/'.$principale->event->slug)->assertOk();

    $response->assertSee(__('events.sections.same_venue'))
        ->assertSee('Altra serata nello stesso locale')
        ->assertSee(__('events.sections.similar'));

    /* La scheda non si ripropone da sola: nelle due griglie di coda non
       compare alcuna card che rimandi all'evento su cui si è già. */
    $cardLink = '<a href="'.route('events.show', $principale->event).'" class="after:absolute';

    expect(substr_count($response->getContent() ?: '', $cardLink))->toBe(0);
});

it('non pubblica una bozza né un evento di un\'altra città', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $bozza = occurrenceAtLocal($city, $category, '2026-09-10 21:30:00', event: [
        'title' => 'Bozza riservata',
        'status' => EventStatus::Draft,
    ]);

    $this->get('/eventi/'.$bozza->event->slug)->assertNotFound();
    $this->get('/eventi/uno-slug-che-non-esiste')->assertNotFound();
});

it('mostra l\'archivio quando tutte le date sono passate, e non finisce negli indici', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $passato = occurrenceAtLocal($city, $category, '2026-08-20 21:30:00', event: ['title' => 'Serata dello scorso mese']);

    $this->get('/eventi/'.$passato->event->slug)
        ->assertOk()
        ->assertSee(__('events.detail.finished'))
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);
});
