<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * L'archiviazione automatica degli scaduti (§14.5).
 *
 * Due metà, e la seconda vale quanto la prima: gli eventi finiti da un pezzo
 * escono dalle liste, e **restano raggiungibili**. §11.9 conta sull'archivio
 * degli eventi passati per la ricerca organica, quindi archiviare non può
 * significare né cancellare né rispondere 404.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

it('archivia un evento le cui date sono tutte passate da più dei giorni previsti', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 12:00');

    // Cento giorni prima: oltre la soglia di novanta.
    $occurrence = occurrenceAtLocal($city, $category, '2026-06-02 21:00');

    $this->artisan('events:archive')->assertSuccessful();

    expect($occurrence->event->refresh()->status)->toBe(EventStatus::Archived);
});

it('lascia stare l evento la cui ultima data è dentro la soglia', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 12:00');

    // Dieci giorni fa: passato, ma non da abbastanza.
    $occurrence = occurrenceAtLocal($city, $category, '2026-08-31 21:00');

    $this->artisan('events:archive')->assertSuccessful();

    expect($occurrence->event->refresh()->status)->toBe(EventStatus::Published);
});

it('non tocca un evento ricorrente con una data ancora futura', function (): void {
    // È il caso che una regola scritta come «l'ultima occorrenza è vecchia»
    // sbaglierebbe: una rassegna cominciata due anni fa e ancora in cartellone.
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 12:00');

    $occurrence = occurrenceAtLocal($city, $category, '2024-01-10 21:00');

    EventOccurrence::factory()->create([
        'event_id' => $occurrence->event_id,
        'starts_at' => CarbonImmutable::parse('2026-12-01 20:00', 'UTC'),
        'ends_at' => null,
        'doors_at' => null,
    ]);

    $this->artisan('events:archive')->assertSuccessful();

    expect($occurrence->event->refresh()->status)->toBe(EventStatus::Published);
});

it('non archivia un evento senza nessuna data', function (): void {
    // Non è scaduto: è incompleto, ed è un'altra riga della dashboard qualità.
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 12:00');

    $event = Event::factory()->create([
        'city_id' => $city->getKey(),
        'category_id' => $category->getKey(),
        'status' => EventStatus::Published,
    ]);

    $this->artisan('events:archive')->assertSuccessful();

    expect($event->refresh()->status)->toBe(EventStatus::Published);
});

it('non tocca gli stati che non sono pubblicato', function (string $status): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 12:00');

    $occurrence = occurrenceAtLocal($city, $category, '2026-01-10 21:00', event: ['status' => $status]);

    $this->artisan('events:archive')->assertSuccessful();

    expect($occurrence->event->refresh()->status->value)->toBe($status);
})->with([
    'bozza' => [EventStatus::Draft->value],
    'annullato' => [EventStatus::Cancelled->value],
    'rifiutato' => [EventStatus::Rejected->value],
]);

it('rispetta la soglia passata da riga di comando', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 12:00');

    $occurrence = occurrenceAtLocal($city, $category, '2026-09-04 21:00');

    $this->artisan('events:archive', ['--days' => 30])->assertSuccessful();
    expect($occurrence->event->refresh()->status)->toBe(EventStatus::Published);

    $this->artisan('events:archive', ['--days' => 3])->assertSuccessful();
    expect($occurrence->event->refresh()->status)->toBe(EventStatus::Archived);
});

it('conta e non tocca nulla con --dry-run', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 12:00');

    $occurrence = occurrenceAtLocal($city, $category, '2026-01-10 21:00');

    $this->artisan('events:archive', ['--dry-run' => true])
        ->expectsOutputToContain(__('console.events_archive.would_archive', ['count' => 1, 'days' => 90]))
        ->assertSuccessful();

    expect($occurrence->event->refresh()->status)->toBe(EventStatus::Published);
});

it('dice che non c è nulla da archiviare quando è così', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 12:00');
    occurrenceAtLocal($city, $category, '2026-09-20 21:00');

    $this->artisan('events:archive')
        ->expectsOutputToContain(__('console.events_archive.empty', ['days' => 90]))
        ->assertSuccessful();
});

it('conta il taglio nel fuso della città e sulla giornata evento', function (): void {
    // Un concerto che comincia dopo mezzanotte in una categoria notturna
    // appartiene alla sera prima (§8.2): archiviarlo un giorno in anticipo
    // sarebbe visibile a chi guarda l'elenco.
    $city = testCity();
    $category = testCategory(['is_nightlife' => true]);

    freezeLocal($city, '2026-09-10 12:00');

    // Ora locale 01:00 del 13 giugno, quindi giornata evento del 12 giugno.
    // Con novanta giorni il taglio è il 12 giugno: la data ci cade sopra e
    // l'evento resta.
    $occurrence = occurrenceAtLocal($city, $category, '2026-06-13 01:00');

    expect($occurrence->refresh()->business_date->format('Y-m-d'))->toBe('2026-06-12');

    $this->artisan('events:archive')->assertSuccessful();
    expect($occurrence->event->refresh()->status)->toBe(EventStatus::Published);

    // Un giorno dopo il taglio si sposta e l'evento passa.
    freezeLocal($city, '2026-09-11 12:00');
    $this->artisan('events:archive')->assertSuccessful();
    expect($occurrence->event->refresh()->status)->toBe(EventStatus::Archived);
});

describe('archiviare toglie dalle liste, non dal sito', function (): void {
    it('sparisce dalla lista degli eventi passati del sito', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-10 12:00');

        $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
        $vecchio = occurrenceAtLocal($city, $category, '2026-01-10 21:00', venue: $venue, event: ['title' => 'Serata di gennaio']);
        occurrenceAtLocal($city, $category, '2026-09-20 21:00', venue: $venue, event: ['title' => 'Serata di settembre']);

        $this->artisan('events:archive')->assertSuccessful();

        expect($vecchio->event->refresh()->status)->toBe(EventStatus::Archived);

        $this->get('/eventi')->assertOk()->assertDontSee('Serata di gennaio')->assertSee('Serata di settembre');
    });

    it('lascia la scheda raggiungibile invece di rispondere 404', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-10 12:00');

        $occurrence = occurrenceAtLocal($city, $category, '2026-01-10 21:00', event: ['title' => 'Serata di gennaio']);

        $this->artisan('events:archive')->assertSuccessful();

        $this->get('/eventi/'.$occurrence->event->slug)
            ->assertOk()
            ->assertSee('Serata di gennaio')
            // L'archivio conserva contenuti e URL condivisi: resta indicizzabile.
            ->assertSee('<meta name="robots" content="index, follow">', escape: false);
    });

    it('resta nell archivio della scheda del locale', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-10 12:00');

        $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
        occurrenceAtLocal($city, $category, '2026-01-10 21:00', venue: $venue, event: ['title' => 'Serata di gennaio']);

        $this->artisan('events:archive')->assertSuccessful();

        $this->get('/locali/'.$venue->slug)->assertOk()->assertSee('Serata di gennaio');
    });

    it('resta nella mappa del sito', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-10 12:00');

        $occurrence = occurrenceAtLocal($city, $category, '2026-01-10 21:00');

        $this->artisan('events:archive')->assertSuccessful();

        $this->get('/sitemap-eventi-1.xml')->assertOk()->assertSee(route('events.show', $occurrence->event), escape: false);
    });

    it('resta leggibile dall API mentre esce dalle sue liste', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-10 12:00');

        $occurrence = occurrenceAtLocal($city, $category, '2026-01-10 21:00');
        $slug = $occurrence->event->slug;

        $this->artisan('events:archive')->assertSuccessful();

        $this->getJson('/api/v1/events/'.$slug)->assertOk();

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonMissing(['slug' => $slug]);
    });

    it('lascia aperto il modulo di segnalazione della scheda', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-10 12:00');

        $occurrence = occurrenceAtLocal($city, $category, '2026-01-10 21:00');

        $this->artisan('events:archive')->assertSuccessful();

        $this->get('/eventi/'.$occurrence->event->slug.'/segnala')->assertOk();
    });
});
