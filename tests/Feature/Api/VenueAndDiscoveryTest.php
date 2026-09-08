<?php

declare(strict_types=1);

use App\Enums\VenueStatus;
use App\Models\Venue;
use App\Support\ContentVersion;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('elenca i locali approvati con il numero di date in programma', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $circolo = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Circolo Arci']);
    Venue::factory()->create(['city_id' => $city->getKey(), 'name' => 'Mai approvato', 'status' => VenueStatus::Draft]);

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $circolo);
    occurrenceAtLocal($city, $category, '2026-09-07 21:00:00', venue: $circolo);

    $data = $this->getJson('/api/v1/venues')->assertOk()->json('data');

    expect(array_column($data, 'name'))->toBe(['Circolo Arci'])
        ->and($data[0]['upcoming_occurrences'])->toBe(2)
        ->and($data[0])->toHaveKeys(['slug', 'address', 'lat', 'lng', 'opening_hours', 'updated_at']);
});

it('apre la scheda di un locale e nega quelli non pubblici', function (): void {
    $city = testCity();

    $approvato = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Teatro Verdi']);
    $bozza = Venue::factory()->create(['city_id' => $city->getKey(), 'status' => VenueStatus::Draft]);

    $this->getJson('/api/v1/venues/'.$approvato->slug)
        ->assertOk()
        ->assertJsonPath('data.name', 'Teatro Verdi');

    $this->getJson('/api/v1/venues/'.$bozza->slug)->assertNotFound();
    $this->getJson('/api/v1/venues/mai-esistito')->assertNotFound();
});

it('elenca le date di un locale con gli stessi filtri della lista', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $circolo = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
    $altro = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Al circolo'], venue: $circolo);
    occurrenceAtLocal($city, $category, '2026-09-06 22:00:00', event: ['title' => 'Altrove'], venue: $altro);

    $data = $this->getJson('/api/v1/venues/'.$circolo->slug.'/events')->assertOk()->json('data');

    expect(array_column($data, 'title'))->toBe(['Al circolo']);

    $this->getJson('/api/v1/venues/mai-esistito/events')->assertNotFound();
});

/*
 * §11.8 e §13.1: il calendario è una sola query aggregata per mese, la stessa
 * del sito. I giorni vuoti non compaiono (§8.6).
 */
it('conta le date giorno per giorno nel mese chiesto', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Prima']);
    occurrenceAtLocal($city, $category, '2026-09-06 22:00:00', event: ['title' => 'Seconda']);
    occurrenceAtLocal($city, $category, '2026-10-02 21:00:00', event: ['title' => 'Il mese dopo']);

    $settembre = $this->getJson('/api/v1/calendar?month=2026-09')->assertOk();

    expect($settembre->json('meta.month'))->toBe('2026-09')
        ->and($settembre->json('meta.total'))->toBe(2)
        ->and($settembre->json('data.0.business_date'))->toBe('2026-09-06')
        ->and($settembre->json('data.0.count'))->toBe(2)
        ->and($settembre->json('data.0.titles'))->toHaveCount(2);

    ContentVersion::bump($city);

    $ottobre = $this->getJson('/api/v1/calendar?month=2026-10')->assertOk();

    expect($ottobre->json('meta.total'))->toBe(1);
});

it('rifiuta un mese scritto male', function (): void {
    testCity();

    $this->getJson('/api/v1/calendar?month=settembre')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

/*
 * §13.3: carico minimale, sette campi per marcatore.
 */
it('spedisce alla mappa il carico minimo di §13.3', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $venue = Venue::factory()->approved()->create([
        'city_id' => $city->getKey(),
        'lat' => 45.4064,
        'lng' => 11.8768,
    ]);

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Concerto'], venue: $venue);

    $data = $this->getJson('/api/v1/map/occurrences')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and(array_keys($data[0]))->toBe(['id', 'event_id', 'lat', 'lng', 'category_id', 'title', 'starts_at'])
        ->and($data[0]['lat'])->toBe(45.4064)
        ->and($data[0]['starts_at'])->toBe('2026-09-06T21:00:00+02:00');
});

it('applica alla mappa gli stessi filtri temporali della lista eventi', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $venue = Venue::factory()->approved()->create([
        'city_id' => $city->getKey(),
        'lat' => 45.4064,
        'lng' => 11.8768,
    ]);

    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', event: ['title' => 'Oggi'], venue: $venue);
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Domani'], venue: $venue);

    expect(array_column($this->getJson('/api/v1/map/occurrences?preset=today')->assertOk()->json('data'), 'title'))
        ->toBe(['Oggi'])
        ->and(array_column($this->getJson('/api/v1/map/occurrences?preset=tomorrow')->assertOk()->json('data'), 'title'))
        ->toBe(['Domani']);
});

it('restringe i marcatori al rettangolo e dichiara quando ne ha tagliati', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $dentro = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'lat' => 45.4064, 'lng' => 11.8768]);
    $fuori = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'lat' => 46.2, 'lng' => 12.9]);

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Dentro'], venue: $dentro);
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Fuori'], venue: $fuori);

    $ristretto = $this->getJson('/api/v1/map/occurrences?bbox=11.80,45.35,11.95,45.45')->assertOk();

    expect(array_column($ristretto->json('data'), 'title'))->toBe(['Dentro'])
        ->and($ristretto->json('meta.truncated'))->toBeFalse();

    $tagliato = $this->getJson('/api/v1/map/occurrences?limit=1')->assertOk();

    expect($tagliato->json('data'))->toHaveCount(1)
        ->and($tagliato->json('meta.truncated'))->toBeTrue();
});

it('rifiuta un rettangolo rovesciato o incompleto', function (): void {
    testCity();

    foreach (['11.9,45.4,11.8,45.5', '11.8,45.3,11.9', 'a,b,c,d'] as $bbox) {
        $this->getJson('/api/v1/map/occurrences?bbox='.$bbox)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }
});

it('cerca eventi, locali e tag e pretende almeno due caratteri', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Circolo Fenice']);
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Fenice in concerto'], venue: $venue);

    $data = $this->getJson('/api/v1/search?q=Fenice')->assertOk()->json('data');

    expect($data)->toHaveKeys(['events', 'venues', 'tags'])
        ->and(array_column($data['venues'], 'name'))->toContain('Circolo Fenice');

    $this->getJson('/api/v1/search?q=a')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');

    $this->getJson('/api/v1/search')->assertStatus(422);
});

it('serializes empty venue maps as JSON objects for native clients', function (): void {
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id, 'socials' => [], 'accessibility' => []]);
    $response = $this->getJson('/api/v1/venues/'.$venue->slug)->assertOk();
    $data = json_decode($response->getContent())->data;
    expect($data->socials)->toBeInstanceOf(stdClass::class)
        ->and($data->accessibility)->toBeInstanceOf(stdClass::class);
});
