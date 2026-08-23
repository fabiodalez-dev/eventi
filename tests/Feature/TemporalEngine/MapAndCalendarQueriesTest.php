<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * I metodi che mappa, calendario e ricerca aggiungono al motore temporale.
 *
 * Stanno lì e non nei controller per la regola di §3 delle convenzioni: se
 * "futuro", "questo mese" o "dentro l'inquadratura" venissero calcolati altrove
 * esisterebbero due definizioni, e divergerebbero.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Un locale in un punto preciso, per poter ragionare sui rettangoli.
 *
 * @param  array<string, mixed>  $attributes
 */
function markerVenueAt(int $cityId, float $lat, float $lng, array $attributes = []): Venue
{
    return Venue::factory()->approved()->create([
        'city_id' => $cityId,
        'lat' => $lat,
        'lng' => $lng,
        'location' => new Point($lat, $lng, 0),
        ...$attributes,
    ]);
}

describe('withinBounds', function (): void {
    it('tiene solo i locali dentro il rettangolo inquadrato', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $dentro = markerVenueAt((int) $city->getKey(), 45.4064, 11.8768, ['name' => 'Dentro il riquadro']);
        $fuori = markerVenueAt((int) $city->getKey(), 45.9000, 12.5000, ['name' => 'Fuori dal riquadro']);

        $atteso = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $dentro);
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $fuori);

        $risultati = EventOccurrenceQuery::for($city)
            ->upcoming()
            ->withinBounds(11.80, 45.35, 11.95, 45.45)
            ->get();

        expect(idsOf($risultati))->toBe([(int) $atteso->getKey()]);
    });

    it('esclude gli eventi senza locale: non hanno un punto da disegnare', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $occorrenza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');
        $occorrenza->event->update(['venue_id' => null]);

        expect(EventOccurrenceQuery::for($city)->upcoming()->withinBounds(-180, -90, 180, 90)->get())->toHaveCount(0);
    });
});

describe('venueMarkers', function (): void {
    it('restituisce un marcatore per locale con il numero delle date', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $locale = markerVenueAt((int) $city->getKey(), 45.4064, 11.8768);

        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $locale);
        occurrenceAtLocal($city, $category, '2026-09-07 21:00:00', venue: $locale);
        occurrenceAtLocal($city, $category, '2026-09-08 21:00:00', venue: $locale);

        $marcatori = EventOccurrenceQuery::for($city)->upcoming()->venueMarkers(100);

        expect($marcatori)->toHaveCount(1)
            ->and($marcatori[0]['venue_id'])->toBe((int) $locale->getKey())
            ->and($marcatori[0]['count'])->toBe(3)
            ->and($marcatori[0]['lat'])->toBe(45.4064)
            ->and($marcatori[0]['lng'])->toBe(11.8768);
    });

    it('prende la categoria dalla data più vicina, che è quella che il marcatore rappresenta', function (): void {
        $city = testCity();
        $musica = testCategory(['name' => 'Musica dal vivo']);
        $teatro = testCategory(['name' => 'Teatro e danza']);

        freezeLocal($city, '2026-09-05 12:00:00');

        $locale = markerVenueAt((int) $city->getKey(), 45.4064, 11.8768);

        occurrenceAtLocal($city, $teatro, '2026-09-10 21:00:00', venue: $locale);
        occurrenceAtLocal($city, $musica, '2026-09-06 21:00:00', venue: $locale);

        $marcatori = EventOccurrenceQuery::for($city)->upcoming()->venueMarkers(100);

        expect($marcatori[0]['category_id'])->toBe((int) $musica->getKey());
    });

    it('conta e disegna in una sola interrogazione', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        foreach (range(1, 5) as $indice) {
            occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: markerVenueAt((int) $city->getKey(), 45.4 + $indice / 100, 11.87));
        }

        $query = EventOccurrenceQuery::for($city)->upcoming();

        $eseguite = 0;
        DB::listen(function () use (&$eseguite): void {
            $eseguite++;
        });

        $marcatori = $query->venueMarkers(100);

        expect($marcatori)->toHaveCount(5)->and($eseguite)->toBe(1);
    });
});

describe('dailyDigest', function (): void {
    it('conta le date e porta i primi titoli di ogni giornata, in una query sola', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        occurrenceAtLocal($city, $category, '2026-09-06 18:00:00', event: ['title' => 'Primo']);
        occurrenceAtLocal($city, $category, '2026-09-06 20:00:00', event: ['title' => 'Secondo']);
        occurrenceAtLocal($city, $category, '2026-09-06 22:00:00', event: ['title' => 'Terzo']);
        occurrenceAtLocal($city, $category, '2026-09-06 23:00:00', event: ['title' => 'Quarto']);
        occurrenceAtLocal($city, $category, '2026-09-08 21:00:00', event: ['title' => 'Altro giorno']);

        $query = EventOccurrenceQuery::for($city)->between('2026-09-01', '2026-09-30');

        $eseguite = 0;
        DB::listen(function () use (&$eseguite): void {
            $eseguite++;
        });

        $digest = $query->dailyDigest(3);

        expect($eseguite)->toBe(1)
            ->and($digest['2026-09-06']['count'])->toBe(4)
            ->and($digest['2026-09-06']['titles'])->toBe(['Primo', 'Secondo', 'Terzo'])
            ->and($digest['2026-09-08']['count'])->toBe(1)
            ->and($digest)->not->toHaveKey('2026-09-07');
    });

    it('non vede le bozze: la base della query è già ristretta al pubblicato', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $occorrenza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Bozza']);
        $occorrenza->event->update(['status' => EventStatus::Draft]);

        expect(EventOccurrenceQuery::for($city)->between('2026-09-01', '2026-09-30')->dailyDigest())->toBe([]);
    });
});

describe('forEvents', function (): void {
    it('è il ponte fra la ricerca testuale e le date future', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $trovato = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

        $risultati = EventOccurrenceQuery::for($city)
            ->upcoming()
            ->forEvents([(int) $trovato->event_id])
            ->get();

        expect(idsOf($risultati))->toBe([(int) $trovato->getKey()]);
    });

    it('con un insieme vuoto non restituisce nulla', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

        expect(EventOccurrenceQuery::for($city)->upcoming()->forEvents([])->get())->toHaveCount(0);
    });
});
