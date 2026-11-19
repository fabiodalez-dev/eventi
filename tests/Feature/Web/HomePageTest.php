<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Support\EventUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('risponde e chiama il prodotto con il nome che sta nella configurazione', function (): void {
    testCity();

    $this->get('/')
        ->assertOk()
        ->assertSee(config()->string('app.name'));
});

/*
 * §8.6: una finestra temporale senza eventi non produce alcuna sezione. Non un
 * contenitore vuoto, non la scritta "nessun evento".
 */
it('non disegna nessuna sezione quando non c\'è niente in programma', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 20:00:00');

    $response = $this->get('/')->assertOk();

    $response->assertDontSee(__('events.sections.ongoing'))
        ->assertDontSee(__('events.sections.starting_soon'))
        ->assertDontSee('id="sezione-tonight"', false)
        ->assertDontSee(__('events.sections.weekend'))
        ->assertDontSee('Nessun evento');
});

/*
 * "In corso" e "Inizia tra poco" si disegnano dentro la pagina, dal server
 * (D49). Erano un frammento caricato dopo il primo disegno, e qui si
 * verificava la presenza del segnaposto: ma il frammento non arrivava mai
 * sulle pagine servite dalla cache, e questo test lo dichiarava corretto.
 * Il contenuto delle due sezioni sta in tests/Feature/Web/LiveNowTest.php.
 */
it('disegna "in corso" e "inizia tra poco" dentro la pagina', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 22:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', '2026-09-05 23:30:00', event: [
        'title' => 'Concerto di prova',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee(__('events.sections.ongoing'))
        ->assertSee(__('events.badge.ongoing'))
        ->assertSee('Concerto di prova');
});

it('scrive "stasera" con l\'orario e il prezzo sulla card', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 15:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:30:00', event: [
        'price_type' => PriceType::Free,
        'price_min' => null,
        'price_max' => null,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee(__('events.sections.tonight'))
        /* L'orario, non l'etichetta che lo incornicia: la card lo scrive nella
           riga della data («oggi alle 21:30») invece che in un badge, e cio'
           che conta e' che chi guarda sappia a che ora — non in quale forma
           gliela si dice. */
        ->assertSee('21:30')
        ->assertSee(__('events.price.free'));
});

it('dichiara annullata una data annullata invece di nasconderla', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 15:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:30:00', occurrence: [
        'status' => OccurrenceStatus::Cancelled,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee(__('events.badge.cancelled'));
});

it('dichiara le misure di ogni immagine per non far ballare la pagina', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 15:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:30:00');

    /*
     * Prima questo test cercava `aspect-[3/4]`, il rapporto della locandina
     * nelle card. Le card non hanno piu' locandina (D46) — ma cio' che il test
     * proteggeva non era quella classe: era che la pagina non si sposti sotto
     * il dito mentre le immagini arrivano. Quella garanzia vale per ogni
     * immagine che resta, e si verifica su tutte invece che su una.
     */
    $html = $this->get('/')->assertOk()->getContent();

    preg_match_all('#<img\b[^>]*>#s', $html, $immagini);

    foreach ($immagini[0] as $tag) {
        expect($tag)->toContain('width=')->toContain('height=');
    }
});

it('mostra l\'attribuzione a OpenStreetMap, che la licenza dei dati impone', function (): void {
    testCity();

    $this->get('/')
        ->assertOk()
        ->assertSee('openstreetmap.org/copyright', escape: false);
});

it('offre il salto al contenuto a chi naviga da tastiera', function (): void {
    testCity();

    $this->get('/')
        ->assertOk()
        ->assertSee(__('ui.skip_to_content'))
        ->assertSee('id="contenuto"', escape: false);
});

it('limits occurrence hydration in the homepage queries', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');

    EventOccurrence::factory()
        ->count(60)
        ->for(Event::factory()->for($city)->published())
        ->create(['starts_at' => now()->addDays(3), 'status' => OccurrenceStatus::Scheduled]);

    $unbounded = [];
    DB::listen(function ($query) use (&$unbounded): void {
        if (str_contains($query->sql, 'select `event_occurrences`.*') && ! str_contains($query->sql, ' limit ')) {
            $unbounded[] = $query->sql;
        }
    });

    $this->get('/')->assertOk();

    expect($unbounded)->toBe([]);
});

it('does not count interested users once per homepage card', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');

    EventOccurrence::factory()
        ->count(12)
        ->for(Event::factory()->for($city)->published())
        ->create(['starts_at' => now()->addDay(), 'status' => OccurrenceStatus::Scheduled]);

    $counts = 0;
    DB::listen(function ($query) use (&$counts): void {
        if (str_contains($query->sql, 'saved_events') && str_contains($query->sql, 'count(*) as `aggregate`')) {
            $counts++;
        }
    });

    $this->get('/')->assertOk();

    expect($counts)->toBe(0);
});

/*
 * La scheda evento idrata tutte le sue date — quelle dell'evento, gli eventi
 * simili e quelli dello stesso locale — in un colpo solo: il numero di query
 * non cresce con il numero di date mostrate (pacchetto `home`, rilevatore N+1).
 */
it('keeps the event page query count flat as related dates grow', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');
    $category = testCategory();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
    $main = occurrenceAtLocal($city, $category, '2026-09-12 21:00:00', event: ['title' => 'Scheda principale'], venue: $venue);

    $count = function () use ($main): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(EventUrl::occurrence($main))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    occurrenceAtLocal($city, $category, '2026-09-13 21:00:00', venue: $venue);
    /* La prima apertura scalda le cache (testata, collegamenti brevi di
       condivisione): si misura dalla seconda. */
    $count();
    $few = $count();

    foreach (range(14, 19) as $giorno) {
        occurrenceAtLocal($city, $category, '2026-09-'.$giorno.' 21:00:00', venue: $venue);
        occurrenceAtLocal($city, $category, '2026-09-'.$giorno.' 22:00:00');
    }

    expect($count())->toBe($few);
});
