<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Services\Calendar\MonthCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Il calendario mensile (§11.8).
 *
 * Il vincolo del piano è esplicito e qui si verifica davvero: **una sola query
 * aggregata per mese**, e quella query in cache.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

it('disegna il mese corrente con conteggi e titoli in anteprima', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-12 21:00:00', event: ['title' => 'Concerto del venerdì']);
    occurrenceAtLocal($city, $category, '2026-09-12 22:30:00', event: ['title' => 'After del venerdì']);

    $this->get('/calendario')
        ->assertOk()
        ->assertSee('Concerto del venerdì')
        ->assertSee('After del venerdì')
        ->assertSee(route('events.date', ['date' => '2026-09-12']), escape: false);
});

it('usa una sola query per il mese e poi la cache', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    foreach (range(10, 20) as $giorno) {
        occurrenceAtLocal($city, $category, '2026-09-'.$giorno.' 21:00:00');
    }

    $calendario = app(MonthCalendar::class);
    $mese = CarbonImmutable::create(2026, 9, 1, 0, 0, 0, $city->timezone);

    $eseguite = 0;
    DB::listen(function () use (&$eseguite): void {
        $eseguite++;
    });

    $calendario->grid($city, $mese);
    expect($eseguite)->toBe(1);

    $eseguite = 0;
    $calendario->grid($city, $mese);
    expect($eseguite)->toBe(0);
});

it('butta via la cache quando un evento viene pubblicato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $calendario = app(MonthCalendar::class);
    $mese = CarbonImmutable::create(2026, 9, 1, 0, 0, 0, $city->timezone);

    expect($calendario->digest($city, $mese))->toBe([]);

    occurrenceAtLocal($city, $category, '2026-09-12 21:00:00', event: ['title' => 'Arrivato dopo']);

    expect($calendario->digest($city, $mese))->toHaveKey('2026-09-12');

    $this->get('/calendario')->assertOk()->assertSee('Arrivato dopo');
});

it('non conta le bozze', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $occorrenza = occurrenceAtLocal($city, $category, '2026-09-12 21:00:00', event: ['title' => 'Bozza segreta']);
    $occorrenza->event->update(['status' => EventStatus::Draft]);

    $this->get('/calendario')->assertOk()->assertDontSee('Bozza segreta');
});

it('ha un indirizzo stabile per ogni mese e i collegamenti ai vicini', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-10-03 21:00:00', event: ['title' => 'Evento di ottobre']);

    $this->get('/calendario/2026-10')
        ->assertOk()
        ->assertSee('Evento di ottobre')
        ->assertSee('<link rel="canonical" href="'.route('calendar.month', ['month' => '2026-10']).'">', escape: false)
        ->assertSee(route('calendar.month', ['month' => '2026-09']), escape: false)
        ->assertSee(route('calendar.month', ['month' => '2026-11']), escape: false);
});

it('rifiuta un mese inesistente o troppo lontano', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');

    $this->get('/calendario/2026-13')->assertNotFound();
    $this->get('/calendario/2026-00')->assertNotFound();
    $this->get('/calendario/2040-01')->assertNotFound();
    $this->get('/calendario/1990-01')->assertNotFound();
});

it('un mese vuoto porta altrove invece di restare spoglio, e non chiede di essere indicizzato', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');

    $this->get('/calendario')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false)
        ->assertSee(__('events.redirects.to_all'))
        ->assertDontSee('Nessun evento');
});

it('un mese pieno entra nell\'indice', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-12 21:00:00');

    $this->get('/calendario')
        ->assertOk()
        ->assertSee('<meta name="robots" content="index, follow">', escape: false);
});

it('non stampa mai una chiave di traduzione grezza', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-12 21:00:00');

    $html = (string) $this->get('/calendario')->assertOk()->getContent();

    expect($html)->not->toMatch('/(calendar|map|search|feeds)\.[a-z_]+\.[a-z_]+/');
});
