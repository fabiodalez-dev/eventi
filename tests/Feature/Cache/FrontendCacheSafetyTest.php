<?php

declare(strict_types=1);

use App\Http\Middleware\CachePage;
use App\Support\CurrentCity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('page_cache.enabled', true);
    $city = testCity();
    freezeLocal($city, '2026-09-12 18:00');
    occurrenceAtLocal($city, testCategory(), '2026-09-12 21:30');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('keeps cached guest HTML private to PHP and never to shared edge caches', function (): void {
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'hit')
        ->assertHeader('X-LiteSpeed-Cache-Control', 'no-cache')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('bypasses personal flash messages and old form input', function (array $session): void {
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');
    $this->withSession($session)->get('/eventi')->assertHeaderMissing('X-Page-Cache');
})->with([
    [['notice' => 'Private', '_flash' => ['new' => ['notice'], 'old' => []]]],
    [['_old_input' => ['email' => 'private@example.test']]],
]);

it('bypasses bearer requests and geographic query coordinates', function (): void {
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');
    $this->get('/eventi', ['Authorization' => 'Bearer private-token'])->assertHeaderMissing('X-Page-Cache');
    $this->get('/eventi?lat=45.4&lng=11.8')->assertHeaderMissing('X-Page-Cache');
});

it('invalidates only HTML while preserving unrelated cache values', function (): void {
    Cache::put('unrelated-session-or-lock', 'preserved', 60);
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'hit');
    $this->artisan('frontend:cache --clear')->assertSuccessful();
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');
    expect(Cache::get('unrelated-session-or-lock'))->toBe('preserved');
});

it('separates hosts and local calendar days', function (): void {
    $this->get('/eventi');
    $middleware = app(CachePage::class);
    $first = $middleware->key(Request::create('https://first.test/eventi'));
    expect($middleware->key(Request::create('https://second.test/eventi')))->not->toBe($first);
    freezeLocal(app(CurrentCity::class)->get(), '2026-09-13 00:00');
    expect($middleware->key(Request::create('https://first.test/eventi')))->not->toBe($first);
});

it('verifies the configured cache store', function (): void {
    $this->artisan('frontend:cache')->assertSuccessful();
});

it('serves live HTML when the optional frontend store is unavailable', function (): void {
    config()->set('cache.stores.unavailable-frontend', ['driver' => 'missing-driver']);
    config()->set('page_cache.store', 'unavailable-frontend');
    $this->get('/eventi')->assertOk()->assertHeaderMissing('X-Page-Cache');
});

it('uses Laravel failover when the primary frontend store is unavailable', function (): void {
    config()->set('cache.stores.unavailable-frontend', ['driver' => 'missing-driver']);
    config()->set('cache.stores.frontend-test', ['driver' => 'failover', 'stores' => ['unavailable-frontend', 'array']]);
    config()->set('page_cache.store', 'frontend-test');
    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'miss');
    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'hit');
});
