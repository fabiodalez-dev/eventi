<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\City;
use App\Models\Tag;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('descrive la piattaforma a chi apre l\'app per la prima volta', function (): void {
    $city = testCity();
    testCategory(['name' => 'Musica dal vivo']);
    Tag::factory()->create(['name' => 'jazz', 'is_approved' => true, 'usage_count' => 12]);

    $response = $this->getJson('/api/v1/config')->assertOk();

    $data = $response->json('data');

    expect($data['api_version'])->toBe('v1')
        ->and($data['min_app_version'])->toHaveKeys(['ios', 'android'])
        ->and($data['features'])->toHaveKeys(['map', 'calendar', 'search', 'submissions', 'reports', 'push'])
        ->and($data['legal'])->toHaveKeys(['terms', 'privacy', 'updated_at'])
        ->and(array_column($data['cities'], 'slug'))->toContain($city->slug)
        ->and(array_column($data['tags'], 'slug'))->toContain('jazz');
});

/*
 * §13.1: «categorie (con i tre flag)». Sono i campi che governano il tempo:
 * senza, l'app non può nemmeno spiegare perché un after delle 2:00 compaia
 * nella serata precedente.
 */
it('porta i tre campi della categoria che governano il tempo', function (): void {
    testCity();
    testCategory(['name' => 'DJ set', 'supports_ongoing' => false, 'is_nightlife' => true, 'default_duration_minutes' => 300]);

    $categories = $this->getJson('/api/v1/config')->assertOk()->json('data.categories');

    expect($categories[0])->toMatchArray([
        'supports_ongoing' => false,
        'is_nightlife' => true,
        'default_duration_minutes' => 300,
    ]);
});

it('non elenca le categorie spente', function (): void {
    testCity();
    testCategory(['name' => 'Attiva']);
    Category::factory()->create(['name' => 'Spenta', 'is_active' => false]);

    $categories = $this->getJson('/api/v1/config')->assertOk()->json('data.categories');

    expect(array_column($categories, 'name'))->toContain('Attiva')->not->toContain('Spenta');
});

it('elenca soltanto le città accese', function (): void {
    $accesa = testCity();
    City::factory()->create(['name' => 'Vicenza', 'is_active' => false]);

    $slugs = array_column($this->getJson('/api/v1/cities')->assertOk()->json('data'), 'slug');

    expect($slugs)->toBe([$accesa->slug]);
});

it('risponde 404 per una città che non esiste o non è ancora accesa', function (): void {
    testCity();
    City::factory()->create(['name' => 'Vicenza', 'slug' => 'vicenza', 'is_active' => false]);

    $this->getJson('/api/v1/cities/vicenza')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CITY_NOT_FOUND');

    $this->getJson('/api/v1/cities/verona')->assertNotFound();
});

it('conta le date future della città', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');
    occurrenceAtLocal($city, $category, '2026-09-07 21:00:00');
    occurrenceAtLocal($city, $category, '2026-09-01 21:00:00');

    $this->getJson('/api/v1/cities/'.$city->slug)
        ->assertOk()
        ->assertJsonPath('data.upcoming_occurrences', 2);
});

/*
 * §13.2: il parametro `city` sceglie la città. Una città sconosciuta non è
 * una lista vuota: è un 404, altrimenti `/v1/events?city=verona` mostrerebbe
 * gli eventi di Padova.
 */
it('sceglie la città dal parametro e rifiuta quelle sconosciute', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

    $this->getJson('/api/v1/events?city='.$city->slug)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/events?city=verona')->assertNotFound()->assertJsonPath('error.code', 'CITY_NOT_FOUND');
});
