<?php

declare(strict_types=1);

use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * §8.5. L'ordine delle sezioni dal vivo, nell'ordine dichiarato dal piano:
 *
 * 1. eventi già in corso prima di quelli che devono iniziare
 * 2. minuti mancanti all'inizio (crescente)
 * 3. distanza dall'utente, se la posizione è stata concessa
 * 4. `editorial_score` decrescente
 * 5. `id`
 *
 * L'ultimo criterio non è decorativo: senza, due righe indistinguibili
 * uscirebbero in ordine arbitrario e nessuno di questi test sarebbe ripetibile.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

/**
 * Locale ancorato a coordinate precise. La colonna `location` è
 * `POINT(lng, lat)` con SRID 0, il solo formato che si comporta allo stesso
 * modo su MariaDB e su MySQL.
 */
function venueAt(int $cityId, float $lat, float $lng): Venue
{
    return Venue::factory()->approved()->create([
        'city_id' => $cityId,
        'lat' => $lat,
        'lng' => $lng,
        'location' => new Point($lat, $lng, 0),
    ]);
}

it('mette per primo chi comincia prima', function (): void {
    $late = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00');
    $early = occurrenceAtLocal($this->city, $this->category, '2026-05-15 19:30');
    $middle = occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00');

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->startingSoon()->get()))->toBe([
        (int) $early->getKey(),
        (int) $middle->getKey(),
        (int) $late->getKey(),
    ]);
});

it('a parità di orario preferisce il punteggio redazionale più alto', function (): void {
    $low = occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00', event: ['editorial_score' => 10]);
    $high = occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00', event: ['editorial_score' => 90]);

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->startingSoon()->get()))->toBe([
        (int) $high->getKey(),
        (int) $low->getKey(),
    ]);
});

it('a parità di tutto ordina per id, così l\'esito è riproducibile', function (): void {
    $first = occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00', event: ['editorial_score' => 50]);
    $second = occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00', event: ['editorial_score' => 50]);
    $third = occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00', event: ['editorial_score' => 50]);

    freezeLocal($this->city, '2026-05-15 19:00');

    $expected = [(int) $first->getKey(), (int) $second->getKey(), (int) $third->getKey()];

    expect(idsOf(EventOccurrenceQuery::for($this->city)->startingSoon()->get()))->toBe($expected)
        ->and(idsOf(EventOccurrenceQuery::for($this->city)->startingSoon()->get()))->toBe($expected);
});

it('a parità di orario mette la distanza prima del punteggio redazionale', function (): void {
    // Centro di Padova, e un locale una decina di chilometri più a nord.
    $near = venueAt((int) $this->city->getKey(), 45.4064, 11.8768);
    $far = venueAt((int) $this->city->getKey(), 45.5064, 11.8768);

    $nearOccurrence = occurrenceAtLocal(
        $this->city,
        $this->category,
        '2026-05-15 20:00',
        event: ['editorial_score' => 1],
        venue: $near,
    );
    $farOccurrence = occurrenceAtLocal(
        $this->city,
        $this->category,
        '2026-05-15 20:00',
        event: ['editorial_score' => 99],
        venue: $far,
    );

    freezeLocal($this->city, '2026-05-15 19:00');

    $results = EventOccurrenceQuery::for($this->city)
        ->startingSoon()
        ->near(45.4064, 11.8768, 30)
        ->get();

    expect(idsOf($results))->toBe([
        (int) $nearOccurrence->getKey(),
        (int) $farOccurrence->getKey(),
    ]);

    // La distanza viaggia con i risultati, non solo nell'ordinamento.
    expect((float) $results[0]->getAttribute(EventOccurrenceQuery::DISTANCE_ALIAS))->toBeLessThan(100.0)
        ->and((float) $results[1]->getAttribute(EventOccurrenceQuery::DISTANCE_ALIAS))->toBeGreaterThan(10_000.0);
});

it('non lascia che la distanza scavalchi l\'orario di inizio', function (): void {
    $near = venueAt((int) $this->city->getKey(), 45.4064, 11.8768);
    $far = venueAt((int) $this->city->getKey(), 45.5064, 11.8768);

    $farButSooner = occurrenceAtLocal($this->city, $this->category, '2026-05-15 19:30', venue: $far);
    $nearButLater = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00', venue: $near);

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->startingSoon()->near(45.4064, 11.8768, 30)->get()))
        ->toBe([(int) $farButSooner->getKey(), (int) $nearButLater->getKey()]);
});

it('esclude dal raggio i locali fuori dal cerchio, non solo fuori dal quadrato', function (): void {
    // Angolo del rettangolo che circoscrive un raggio di 10 km: dentro il
    // quadrato ma fuori dal cerchio, a circa 14 km dal centro.
    $inside = venueAt((int) $this->city->getKey(), 45.4064, 11.8768);
    $corner = venueAt((int) $this->city->getKey(), 45.4962, 12.0048);

    $insideOccurrence = occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00', venue: $inside);
    occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00', venue: $corner);

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->startingSoon()->near(45.4064, 11.8768, 10)->get()))
        ->toBe([(int) $insideOccurrence->getKey()]);
});

it('ordina le sezioni in corso per orario di inizio, poi per punteggio, poi per id', function (): void {
    $started1900 = occurrenceAtLocal($this->city, $this->category, '2026-05-15 19:00', '2026-05-15 23:00');
    $started1800Low = occurrenceAtLocal($this->city, $this->category, '2026-05-15 18:00', '2026-05-15 23:00', event: ['editorial_score' => 5]);
    $started1800High = occurrenceAtLocal($this->city, $this->category, '2026-05-15 18:00', '2026-05-15 23:00', event: ['editorial_score' => 80]);

    freezeLocal($this->city, '2026-05-15 20:00');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->ongoing()->get()))->toBe([
        (int) $started1800High->getKey(),
        (int) $started1800Low->getKey(),
        (int) $started1900->getKey(),
    ]);
});

it('mette in cima gli eventi in evidenza quando si ordina per rilevanza', function (): void {
    $ordinary = occurrenceAtLocal($this->city, $this->category, '2026-05-15 18:00', event: ['editorial_score' => 70]);
    $featured = occurrenceAtLocal($this->city, $this->category, '2026-05-15 22:00', event: [
        'is_featured' => true,
        'featured_until' => null,
        'editorial_score' => 0,
    ]);
    $expired = occurrenceAtLocal($this->city, $this->category, '2026-05-15 17:00', event: [
        'is_featured' => true,
        'featured_until' => localInstant($this->city, '2026-05-01 00:00')->utc(),
        'editorial_score' => 0,
    ]);

    freezeLocal($this->city, '2026-05-15 09:00');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->orderByRelevance()->get()))->toBe([
        (int) $featured->getKey(),
        (int) $ordinary->getKey(),
        (int) $expired->getKey(),
    ]);
});

it('non accumula ordinamenti né filtri se la stessa query viene eseguita più volte', function (): void {
    $first = occurrenceAtLocal($this->city, $this->category, '2026-05-15 19:30');
    $second = occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:30');

    freezeLocal($this->city, '2026-05-15 19:00');

    $query = EventOccurrenceQuery::for($this->city)->startingSoon();
    $expected = [(int) $first->getKey(), (int) $second->getKey()];

    expect(idsOf($query->get()))->toBe($expected)
        ->and(idsOf($query->get()))->toBe($expected)
        ->and(idsOf($query->paginate(perPage: 10)->items()))->toBe($expected)
        ->and(idsOf($query->cursorPaginate(perPage: 10)->items()))->toBe($expected);
});

it('impagina senza perdere né ripetere occorrenze', function (): void {
    $ids = [];

    foreach (['19:10', '19:20', '19:30', '19:40', '19:50'] as $time) {
        $ids[] = (int) occurrenceAtLocal($this->city, $this->category, "2026-05-15 {$time}")->getKey();
    }

    freezeLocal($this->city, '2026-05-15 19:00');

    $first = EventOccurrenceQuery::for($this->city)->startingSoon()->paginate(perPage: 2, page: 1);
    $second = EventOccurrenceQuery::for($this->city)->startingSoon()->paginate(perPage: 2, page: 2);
    $third = EventOccurrenceQuery::for($this->city)->startingSoon()->paginate(perPage: 2, page: 3);

    expect($first->total())->toBe(5)
        ->and([...idsOf($first->items()), ...idsOf($second->items()), ...idsOf($third->items())])->toBe($ids);
});

it('impagina a cursore anche le sezioni dal vivo, oltre la prima pagina', function (): void {
    $ids = [];

    foreach (['19:10', '19:20', '19:30'] as $time) {
        $ids[] = (int) occurrenceAtLocal($this->city, $this->category, "2026-05-15 {$time}")->getKey();
    }

    freezeLocal($this->city, '2026-05-15 19:00');

    $page = EventOccurrenceQuery::for($this->city)->startingSoon()->cursorPaginate(perPage: 1);
    $collected = idsOf($page->items());

    while ($page->hasMorePages()) {
        $page = EventOccurrenceQuery::for($this->city)
            ->startingSoon()
            ->cursorPaginate(perPage: 1, cursor: (string) $page->nextCursor()?->encode());

        $collected = [...$collected, ...idsOf($page->items())];
    }

    expect($collected)->toBe($ids);
});

it('impagina a cursore anche l\'ordinamento per rilevanza', function (): void {
    $featured = occurrenceAtLocal($this->city, $this->category, '2026-05-15 22:00', event: [
        'is_featured' => true,
        'featured_until' => null,
        'editorial_score' => 0,
    ]);
    $scored = occurrenceAtLocal($this->city, $this->category, '2026-05-15 18:00', event: ['editorial_score' => 40]);
    $plain = occurrenceAtLocal($this->city, $this->category, '2026-05-15 19:00', event: ['editorial_score' => 0]);

    freezeLocal($this->city, '2026-05-15 09:00');

    $page = EventOccurrenceQuery::for($this->city)->today()->orderByRelevance()->cursorPaginate(perPage: 1);
    $collected = idsOf($page->items());

    while ($page->hasMorePages()) {
        $page = EventOccurrenceQuery::for($this->city)
            ->today()
            ->orderByRelevance()
            ->cursorPaginate(perPage: 1, cursor: (string) $page->nextCursor()?->encode());

        $collected = [...$collected, ...idsOf($page->items())];
    }

    expect($collected)->toBe([
        (int) $featured->getKey(),
        (int) $scored->getKey(),
        (int) $plain->getKey(),
    ]);
});

it('impagina a cursore anche quando la distanza entra nell\'ordinamento', function (): void {
    $ids = [];

    foreach ([45.4064, 45.4164, 45.4264] as $lat) {
        $venue = venueAt((int) $this->city->getKey(), $lat, 11.8768);
        $ids[] = (int) occurrenceAtLocal($this->city, $this->category, '2026-05-15 20:00', venue: $venue)->getKey();
    }

    freezeLocal($this->city, '2026-05-15 19:00');

    $build = fn (): EventOccurrenceQuery => EventOccurrenceQuery::for($this->city)
        ->startingSoon()
        ->near(45.4064, 11.8768, 30);

    $page = $build()->cursorPaginate(perPage: 1);
    $collected = idsOf($page->items());

    while ($page->hasMorePages()) {
        $page = $build()->cursorPaginate(perPage: 1, cursor: (string) $page->nextCursor()?->encode());
        $collected = [...$collected, ...idsOf($page->items())];
    }

    expect($collected)->toBe($ids);
});
