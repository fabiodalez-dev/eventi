<?php

declare(strict_types=1);

use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Geo\GeoQueryInterface;
use Illuminate\Support\Facades\DB;

/**
 * §4 delle convenzioni: le coordinate si scrivono **sempre** come
 * `POINT(lng, lat)` con SRID 0, e nessun `ST_*` esiste fuori da
 * `App\Services\Geo`. Qui si verifica che il formato memorizzato sia quello e
 * che le due funzioni di `GeoQueryInterface` — cerchio e rettangolo — filtrino
 * davvero, non per approssimazione.
 */

/**
 * Piazza delle Erbe, Padova: il punto di riferimento di tutte le distanze.
 */
const PADOVA_LAT = 45.4064;

const PADOVA_LNG = 11.8768;

/**
 * Un grado di latitudine vale ~111.32 km: spostarsi in verticale è il solo modo
 * di ottenere una distanza nota senza rifare il calcolo del geoide.
 */
function latOffsetForKm(float $km): float
{
    return PADOVA_LAT + $km / 111.32;
}

/**
 * Distanza in metri calcolata dal database, che è il metro di paragone: se il
 * codice applicativo e MariaDB non concordano, è il codice a sbagliare.
 */
function sphereDistance(Venue $venue, float $lat, float $lng): float
{
    /** @var object{meters: float|string} $row */
    $row = DB::selectOne(
        'SELECT ST_Distance_Sphere(location, ST_GeomFromText(?, 0)) as meters FROM venues WHERE id = ?',
        [sprintf('POINT(%.8F %.8F)', $lng, $lat), $venue->getKey()],
    );

    return (float) $row->meters;
}

/**
 * @return array<int, string> nomi dei locali restituiti, ordinati
 */
function namesWithinRadius(float $lat, float $lng, float $radiusKm): array
{
    $query = Venue::query();
    app(GeoQueryInterface::class)->withinRadius($query, $lat, $lng, $radiusKm, 'venues.location');

    return $query->orderBy('name')->pluck('name')->all();
}

it('memorizza le coordinate come POINT(lng, lat) con SRID 0', function (): void {
    $city = testCity();
    Venue::factory()->approved()->at(PADOVA_LAT, PADOVA_LNG)->create([
        'city_id' => $city->getKey(),
        'name' => 'Padova centro',
    ]);

    /** @var object{wkt: string, x: float|string, y: float|string, srid: int|string} $row */
    $row = DB::selectOne(
        'SELECT ST_AsText(location) as wkt, ST_X(location) as x, ST_Y(location) as y, ST_SRID(location) as srid
         FROM venues WHERE name = ?',
        ['Padova centro'],
    );

    // ST_X è la longitudine, ST_Y la latitudine: è l'ordine di POINT(lng lat).
    expect((float) $row->x)->toBe(PADOVA_LNG)
        ->and((float) $row->y)->toBe(PADOVA_LAT)
        ->and((int) $row->srid)->toBe(0)
        ->and($row->wkt)->toContain('11.8768')
        ->and($row->wkt)->toStartWith('POINT(11.8768');
});

it('colloca un locale di Padova a Padova e non in Somalia', function (): void {
    $city = testCity();
    $venue = Venue::factory()->approved()->at(PADOVA_LAT, PADOVA_LNG)->create([
        'city_id' => $city->getKey(),
        'name' => 'Padova centro',
    ]);

    // Con lat e lng invertite il punto finirebbe a 11.87 N, 45.40 E: golfo di
    // Aden, al largo della Somalia. È la trappola che §4 delle convenzioni
    // esiste per evitare, e a differenza di un errore di formato non fa
    // fallire nulla: restituisce semplicemente sempre zero risultati.
    expect(sphereDistance($venue, PADOVA_LAT, PADOVA_LNG))->toBeLessThan(1.0)
        ->and(sphereDistance($venue, PADOVA_LNG, PADOVA_LAT))->toBeGreaterThan(3_000_000.0);

    expect(namesWithinRadius(PADOVA_LAT, PADOVA_LNG, 5))->toBe(['Padova centro'])
        ->and(namesWithinRadius(PADOVA_LNG, PADOVA_LAT, 50))->toBe([]);
});

it('tiene dentro il raggio di 5 km un locale a 2 km e fuori uno a 30 km', function (): void {
    $city = testCity();

    $vicino = Venue::factory()->approved()->at(latOffsetForKm(2), PADOVA_LNG)->create([
        'city_id' => $city->getKey(),
        'name' => 'A due chilometri',
    ]);

    $lontano = Venue::factory()->approved()->at(latOffsetForKm(30), PADOVA_LNG)->create([
        'city_id' => $city->getKey(),
        'name' => 'A trenta chilometri',
    ]);

    // Le distanze sono quelle attese, a meno di qualche decina di metri di
    // scarto fra approssimazione sferica e formula usata per posizionarli.
    expect(sphereDistance($vicino, PADOVA_LAT, PADOVA_LNG))->toBeBetween(1_950.0, 2_050.0)
        ->and(sphereDistance($lontano, PADOVA_LAT, PADOVA_LNG))->toBeBetween(29_800.0, 30_200.0);

    expect(namesWithinRadius(PADOVA_LAT, PADOVA_LNG, 5))->toBe(['A due chilometri'])
        ->and(namesWithinRadius(PADOVA_LAT, PADOVA_LNG, 35))->toBe(['A due chilometri', 'A trenta chilometri']);
});

it('raffina il rettangolo in cerchio: un angolo dell\'envelope resta fuori', function (): void {
    $city = testCity();

    // Nord-est a 4 km per lato: dentro il bounding box di 5 km, fuori dal
    // cerchio (la diagonale vale ~5.66 km). È il passo che distingue
    // MBRContains da ST_Distance_Sphere.
    $lat = latOffsetForKm(4);
    $lng = PADOVA_LNG + 4 / (111.32 * cos(deg2rad(PADOVA_LAT)));

    $angolo = Venue::factory()->approved()->at($lat, $lng)->create([
        'city_id' => $city->getKey(),
        'name' => 'Angolo del rettangolo',
    ]);

    expect(sphereDistance($angolo, PADOVA_LAT, PADOVA_LNG))->toBeGreaterThan(5_000.0)
        ->and(namesWithinRadius(PADOVA_LAT, PADOVA_LNG, 5))->toBe([]);

    // Lo stesso punto è invece dentro il rettangolo che lo circoscrive.
    $query = Venue::query();
    app(GeoQueryInterface::class)->withinBounds(
        $query,
        PADOVA_LNG - 0.1,
        PADOVA_LAT - 0.1,
        PADOVA_LNG + 0.1,
        PADOVA_LAT + 0.1,
        'venues.location',
    );

    expect($query->pluck('name')->all())->toBe(['Angolo del rettangolo']);
});

it('restituisce con withinBounds soltanto ciò che sta dentro il rettangolo', function (): void {
    $city = testCity();

    $dentro = [
        'Centro' => [PADOVA_LAT, PADOVA_LNG],
        'Angolo sud-ovest' => [45.3800, 11.8400],
        'Angolo nord-est' => [45.4300, 11.9100],
    ];

    $fuori = [
        'Troppo a nord' => [45.4400, 11.8768],
        'Troppo a sud' => [45.3700, 11.8768],
        'Troppo a est' => [45.4064, 11.9200],
        'Troppo a ovest' => [45.4064, 11.8300],
    ];

    foreach ([...$dentro, ...$fuori] as $name => [$lat, $lng]) {
        Venue::factory()->approved()->at($lat, $lng)->create([
            'city_id' => $city->getKey(),
            'name' => $name,
        ]);
    }

    $query = Venue::query();
    // Ordine dei parametri identico a `bbox=minLng,minLat,maxLng,maxLat` di §13.2.
    app(GeoQueryInterface::class)->withinBounds($query, 11.8400, 45.3800, 11.9100, 45.4300, 'venues.location');

    expect($query->orderBy('name')->pluck('name')->all())
        ->toBe(['Angolo nord-est', 'Angolo sud-ovest', 'Centro']);
});

it('non fa passare per il rettangolo un locale con le coordinate invertite', function (): void {
    $city = testCity();

    // Se il POINT fosse scritto come (lat, lng) questo locale cadrebbe dentro
    // un rettangolo che descrive tutt'altra parte del mondo.
    Venue::factory()->approved()->at(PADOVA_LAT, PADOVA_LNG)->create([
        'city_id' => $city->getKey(),
        'name' => 'Padova centro',
    ]);

    $query = Venue::query();
    app(GeoQueryInterface::class)->withinBounds($query, 45.3800, 11.8400, 45.4300, 11.9100, 'venues.location');

    expect($query->pluck('name')->all())->toBe([]);
});

it('espone con near() una distanza coerente con ST_Distance_Sphere', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 12:00');

    $vicino = Venue::factory()->approved()->at(latOffsetForKm(2), PADOVA_LNG)->create([
        'city_id' => $city->getKey(),
        'name' => 'A due chilometri',
    ]);

    $lontano = Venue::factory()->approved()->at(latOffsetForKm(30), PADOVA_LNG)->create([
        'city_id' => $city->getKey(),
        'name' => 'A trenta chilometri',
    ]);

    occurrenceAt($city, $category, '2026-09-10 19:00:00', venue: $vicino);
    occurrenceAt($city, $category, '2026-09-10 20:00:00', venue: $lontano);

    $results = EventOccurrenceQuery::for($city)->today()->near(PADOVA_LAT, PADOVA_LNG, 5)->get();

    expect($results)->toHaveCount(1);

    $occurrence = $results->first();

    expect($occurrence->event->venue_id)->toBe($vicino->getKey())
        ->and((float) $occurrence->getAttribute(EventOccurrenceQuery::DISTANCE_ALIAS))
        ->toEqualWithDelta(sphereDistance($vicino, PADOVA_LAT, PADOVA_LNG), 0.001);

    // Allargando il raggio compaiono entrambi, ciascuno con la propria distanza.
    $distances = EventOccurrenceQuery::for($city)->today()->near(PADOVA_LAT, PADOVA_LNG, 35)->get()
        ->mapWithKeys(fn ($result): array => [
            $result->event->venue_id => (float) $result->getAttribute(EventOccurrenceQuery::DISTANCE_ALIAS),
        ]);

    expect($distances)->toHaveCount(2)
        ->and($distances[$vicino->getKey()])->toEqualWithDelta(sphereDistance($vicino, PADOVA_LAT, PADOVA_LNG), 0.001)
        ->and($distances[$lontano->getKey()])->toEqualWithDelta(sphereDistance($lontano, PADOVA_LAT, PADOVA_LNG), 0.001);
});

it('esclude da near() gli eventi senza locale registrato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 12:00');

    $venue = Venue::factory()->approved()->at(PADOVA_LAT, PADOVA_LNG)->create(['city_id' => $city->getKey()]);

    occurrenceAt($city, $category, '2026-09-10 19:00:00', venue: $venue);
    // Un corteo in piazza non ha coordinate su `venues.location`: senza locale
    // non può entrare in un raggio, e non deve entrarci per sbaglio.
    occurrenceAt($city, $category, '2026-09-10 20:00:00', event: ['venue_id' => null]);

    $results = EventOccurrenceQuery::for($city)->today()->near(PADOVA_LAT, PADOVA_LNG, 5)->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->event->venue_id)->toBe($venue->getKey());
});
