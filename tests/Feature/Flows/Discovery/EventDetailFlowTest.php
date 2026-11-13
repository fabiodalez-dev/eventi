<?php

declare(strict_types=1);

use App\Support\EventUrl;
use Carbon\Carbon;

require_once __DIR__.'/Helpers.php';

/*
 * La scheda dell'evento e il motore temporale visti da chi la apre: una
 * rassegna con più date, un laboratorio che è in corso e poi finisce, una
 * serata che diventa passato mentre la scheda resta raggiungibile.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

it('apre una rassegna, sceglie una data e ne scarica il calendario', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    $prima = occurrenceAtLocal($city, $category, '2026-09-10 21:30:00', '2026-09-10 23:30:00', event: ['title' => 'Rassegna di cinema muto']);
    $event = $prima->event;
    $seconda = $event->occurrences()->create([
        'starts_at' => localInstant($city, '2026-09-17 21:30:00')->utc(),
        'ends_at' => localInstant($city, '2026-09-17 23:30:00')->utc(),
        'is_all_day' => false,
    ])->fresh();
    $altro = occurrenceAtLocal($city, $category, '2026-09-11 21:00:00', event: ['title' => 'Altro evento in città']);

    expect($seconda->url_number)->not->toBe($prima->url_number);

    /* Dalla lista: due card della stessa rassegna, una per data. */
    $this->get('/eventi')
        ->assertOk()
        ->assertSee(EventUrl::occurrence($prima), escape: false)
        ->assertSee(EventUrl::occurrence($seconda), escape: false);

    /* La scheda dell'evento. */
    $this->get('/eventi/'.$event->slug)->assertOk()->assertSee('Rassegna di cinema muto');

    /* La seconda data ha un proprio indirizzo canonico. */
    $data = $this->get('/eventi/'.$event->slug.'/'.$seconda->url_number)
        ->assertOk()
        ->assertSee('Rassegna di cinema muto');

    expect(scopertaCanonico($data))->toBe(EventUrl::occurrence($seconda));

    /* Il calendario di quella data, non della prima. */
    $ics = $this->get('/eventi/'.$event->slug.'/'.$seconda->url_number.'/calendario.ics')
        ->assertOk()
        ->assertHeader('content-type', 'text/calendar; charset=utf-8');

    expect((string) $ics->getContent())
        ->toContain('BEGIN:VCALENDAR')
        ->toContain('DTSTART:20260917T193000Z')
        ->toContain('DTEND:20260917T213000Z')
        ->not->toContain('DTSTART:20260910T193000Z');

    expect((string) $ics->headers->get('content-disposition'))->toContain('attachment');

    /* Un numero di data che questo evento non ha: 404, anche se esiste altrove. */
    $inesistente = max($prima->url_number, $seconda->url_number) + 50;
    $this->get('/eventi/'.$event->slug.'/'.$inesistente)->assertNotFound();
    $this->get('/eventi/'.$event->slug.'/'.$inesistente.'/calendario.ics')->assertNotFound();
    $this->get('/eventi/'.$altro->event->slug.'/'.$altro->url_number)->assertOk()->assertSee('Altro evento in città');
});

it('mostra un evento in corso mentre succede e lo toglie quando è finito', function (): void {
    $city = testCity();
    $category = testCategory(['supports_ongoing' => true]);

    freezeLocal($city, '2026-09-10 19:00:00');

    $laboratorio = occurrenceAtLocal($city, $category, '2026-09-10 18:00:00', '2026-09-10 20:00:00', event: ['title' => 'Laboratorio di ceramica']);
    $concerto = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', '2026-09-10 23:00:00', event: ['title' => 'Concerto delle nove']);

    /* Alle 19:00 il laboratorio è in corso, il concerto comincia fra poco. */
    expect(scopertaTitoli($this->getJson('/api/v1/events?preset=ongoing')->assertOk()->json('data')))->toBe(['Laboratorio di ceramica'])
        ->and(scopertaTitoli($this->getJson('/api/v1/events?preset=starting_soon')->assertOk()->json('data')))->toBe(['Concerto delle nove']);

    $home = $this->getJson('/api/v1/home')->assertOk();
    expect(collect($home->json('data.sections.ongoing'))->pluck('occurrence_id')->all())->toBe([(int) $laboratorio->id])
        ->and(collect($home->json('data.sections.starting_soon'))->pluck('occurrence_id')->all())->toBe([(int) $concerto->id]);

    $this->get('/eventi?date=ongoing')
        ->assertOk()
        ->assertSee('Laboratorio di ceramica')
        ->assertDontSee('Concerto delle nove');

    /* Alle 20:01 è finito: sparisce da «in corso» sul sito e nell'app. */
    freezeLocal($city, '2026-09-10 20:01:00');

    expect($this->getJson('/api/v1/events?preset=ongoing')->assertOk()->json('data'))->toBe([])
        ->and(scopertaTitoli($this->getJson('/api/v1/events?preset=starting_soon')->assertOk()->json('data')))->toBe(['Concerto delle nove']);

    $this->getJson('/api/v1/home')->assertOk()->assertJsonCount(0, 'data.sections.ongoing');

    $this->get('/eventi?date=ongoing')->assertOk()->assertDontSee('Laboratorio di ceramica');

    /* Alle 22:00 tocca al concerto. */
    freezeLocal($city, '2026-09-10 22:00:00');

    expect(scopertaTitoli($this->getJson('/api/v1/events?preset=ongoing')->assertOk()->json('data')))->toBe(['Concerto delle nove']);
});

it('toglie dalle liste una serata diventata passato ma ne tiene raggiungibile la scheda', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    $serata = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', '2026-09-10 23:00:00', event: ['title' => 'Concerto da ricordare']);
    $slug = $serata->event->slug;

    $this->get('/eventi')->assertOk()->assertSee('Concerto da ricordare');
    expect(scopertaTitoli($this->getJson('/api/v1/events')->assertOk()->json('data')))->toBe(['Concerto da ricordare']);

    /* Una settimana dopo. */
    freezeLocal($city, '2026-09-17 12:00:00');

    $this->get('/eventi')->assertOk()->assertDontSee('Concerto da ricordare');
    expect($this->getJson('/api/v1/events')->assertOk()->json('data'))->toBe([]);
    $this->get('/cerca?q=ricordare')->assertOk()->assertDontSee('Concerto da ricordare');

    /* Chi arriva da un link condiviso trova ancora la scheda, con la data passata. */
    $this->get('/eventi/'.$slug)->assertOk()->assertSee('Concerto da ricordare');
    $this->get(EventUrl::occurrence($serata))->assertOk()->assertSee('Concerto da ricordare');

    $scheda = $this->getJson('/api/v1/events/'.$slug)->assertOk();
    expect($scheda->json('data.occurrences'))->toHaveCount(1)
        ->and($scheda->json('data.occurrences.0.occurrence_id'))->toBe((int) $serata->id);

    /* L'elenco delle date future, invece, è vuoto. */
    $this->getJson('/api/v1/events/'.$slug.'/occurrences')->assertOk()->assertJsonCount(0, 'data');
});
