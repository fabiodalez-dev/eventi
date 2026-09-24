<?php

use App\Models\User;
use App\Models\Venue;
use App\Services\RememberedLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('requires explicit remembering consent and valid coordinates', function (): void {
    $this->postJson('/posizione-ricordata', ['lat' => 45, 'lng' => 12])->assertUnprocessable();
    $this->postJson('/posizione-ricordata', ['lat' => 91, 'lng' => 12, 'remember' => true])->assertUnprocessable();
});

/*
 * Dal 24/09/2026 la posizione non è più arrotondata a due decimali: si
 * conserva com'è, perché serve a calcolare una distanza (`docs/DECISIONS.md`).
 * Quello che non cambia è il resto — una sola posizione, cifrata, sei mesi,
 * cancellabile — ed è ciò che questo test continua a verificare.
 */
it('remembers only one encrypted location for six months', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/posizione-ricordata', ['lat' => 45.406733, 'lng' => 11.876814, 'remember' => true])
        ->assertOk()->assertJsonPath('data.lat', 45.406733)->assertJsonPath('data.lng', 11.876814)
        ->assertJsonPath('data.expires_at', now()->addMonthsNoOverflow(6)->timestamp)
        ->assertCookie(RememberedLocation::COOKIE);
    // Cifrata: il valore non compare in chiaro nella colonna che lo contiene.
    expect(DB::table('users')->where('id', $user->id)->value('remembered_location'))->not->toContain('45.406733');
    $this->postJson('/posizione-ricordata', ['lat' => 44, 'lng' => 10, 'remember' => true])->assertOk();
    expect($user->fresh()->remembered_location['lat'])->toEqual(44);
    expect($user->fresh()->toArray())->not->toHaveKey('remembered_location');
    $this->deleteJson('/posizione-ricordata')->assertOk()->assertCookieExpired(RememberedLocation::COOKIE);
    expect($user->fresh()->remembered_location)->toBeNull();
});

it('ignores expired locations and restores the default', function (): void {
    $user = User::factory()->create();
    app(RememberedLocation::class)->save(45, 12, $user);
    $this->travel(7)->months();
    $this->actingAs($user)->getJson('/posizione-ricordata')->assertJsonPath('data', null);
});

it('synchronizes a guest position without extending its lifetime or replacing a newer one', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $observed = now()->subMonthsNoOverflow(2)->timestamp;
    $this->actingAs($user)->postJson('/posizione-ricordata', ['lat' => 45, 'lng' => 12, 'remember' => true, 'observed_at' => $observed])
        ->assertOk()->assertJsonPath('data.saved_at', $observed)
        ->assertJsonPath('data.expires_at', CarbonImmutable::createFromTimestamp($observed)->addMonthsNoOverflow(6)->timestamp);
    $this->postJson('/posizione-ricordata', ['lat' => 44, 'lng' => 10, 'remember' => true])->assertOk();
    $this->postJson('/posizione-ricordata', ['lat' => 45, 'lng' => 12, 'remember' => true, 'observed_at' => $observed])
        ->assertOk()->assertJsonPath('data.lat', 44);
});

it('does not expose authenticated or geolocated home responses to shared caches', function (): void {
    testCity();
    $this->getJson('/api/v1/home?near=45.41,11.88')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('places nearby immediately after today and applies the selected radius on web and API', function (): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-15 12:00');
    occurrenceAtLocal($city, $category, '2026-09-15 15:00');
    $far = occurrenceAtLocal($city, $category, '2026-09-16 20:00', venue: Venue::factory()->approved()->at(45.47, 11.88)->create(['city_id' => $city->id]));
    occurrenceAtLocal($city, $category, '2026-09-16 19:00', venue: Venue::factory()->approved()->at(45.41, 11.88)->create(['city_id' => $city->id]));
    $position = app(RememberedLocation::class)->save(45.41, 11.88, null);
    $this->withCookie(RememberedLocation::COOKIE, json_encode($position));
    $five = $this->get('/?nearby_radius=5')->assertOk();
    $five->assertViewHas('nearby', fn ($items) => ! $items->contains('id', $far->id));
    $five->assertSeeInOrder(['id="sezione-today"', 'data-home-nearby'], false);
    $this->get('/?nearby_radius=10')->assertOk()->assertViewHas('nearby', fn ($items) => $items->contains('id', $far->id));
    expect($this->getJson('/api/v1/home?near=45.41,11.88&radius_km=5')->assertOk()->json('data.sections.nearby.*.occurrence_id'))->not->toContain($far->id);
    expect($this->getJson('/api/v1/home?near=45.41,11.88&radius_km=10')->assertOk()->json('data.sections.nearby.*.occurrence_id'))->toContain($far->id);
    $this->getJson('/?nearby_radius=100')->assertUnprocessable();
});

it('omits empty weekend and nearby sections including their controls', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-15 12:00');
    occurrenceAtLocal($city, testCategory(), '2026-09-15 15:00', venue: Venue::factory()->approved()->at(43.6, 12.9)->create(['city_id' => $city->id]));
    $this->get('/')->assertOk()
        ->assertSee('id="sezione-today"', false)
        ->assertDontSee('id="sezione-weekend"', false)
        ->assertDontSee('data-home-nearby', false)
        ->assertDontSee('data-location-use', false);
});

it('uses the visitor cookie for nearby instead of the city center without caching it', function (): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-15 12:00');
    $near = occurrenceAtLocal($city, $category, '2026-09-16 20:00', venue: Venue::factory()->approved()->at(45.7, 11.9)->create(['city_id' => $city->id]));
    $position = app(RememberedLocation::class)->save(45.7, 11.9, null);
    $this->withCookie(RememberedLocation::COOKIE, json_encode($position))->get('/')
        ->assertOk()->assertViewHas('nearby', fn ($items) => $items->contains('id', $near->id));
});

it('isolates account locations and uses them in the native home', function (): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-15 12:00');
    $near = occurrenceAtLocal($city, $category, '2026-09-16 20:00', venue: Venue::factory()->approved()->at(45.7, 11.9)->create(['city_id' => $city->id]));
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/me/location', ['lat' => 45.7, 'lng' => 11.9, 'remember' => true])->assertOk();
    $response = $this->getJson('/api/v1/home')->assertOk();
    expect($response->json('data.sections.nearby.*.occurrence_id'))->toContain($near->id);
    $other = User::factory()->create();
    app('auth')->forgetGuards();
    $this->withToken($other->createToken('test')->plainTextToken)->getJson('/api/v1/me/location')->assertJsonPath('data', null);
});
