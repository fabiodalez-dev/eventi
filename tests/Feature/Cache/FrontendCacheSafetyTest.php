<?php

declare(strict_types=1);

use App\Http\Middleware\CachePage;
use App\Models\Category;
use App\Models\User;
use App\Models\Venue;
use App\Services\Search\FilterFacets;
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
        /*
         * `private` è la parte che fa il lavoro: nessuna cache condivisa —
         * LiteSpeed, un CDN, un proxy aziendale — può trattenere un HTML che
         * porta il token CSRF di una sessione.
         *
         * `no-cache` e non `no-store` perché il secondo vieta anche la
         * back/forward cache del browser, che non è una cache di rete ma il
         * ripristino della pagina viva: su questo sito il gesto più frequente
         * è aprire una scheda e tornare indietro, e con `no-store` ogni
         * ritorno era una richiesta completa. Vedi `PreventSharedResponseCache`.
         */
        ->assertHeader('Cache-Control', 'no-cache, private');
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

it('treats an empty optional store as the default store', function (): void {
    config()->set('page_cache.store', '');
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'hit');
});

it('invalidates public HTML after venue or category edits', function (): void {
    $venue = Venue::factory()->create(['city_id' => app(CurrentCity::class)->get()->id]);
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'hit');
    $venue->update(['name' => 'New venue name']);
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');

    $facets = app(FilterFacets::class);
    $facets->categories();
    $category = Category::firstOrFail();
    $category->update(['name' => 'New category name']);
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');
    expect($facets->categories()->firstWhere('id', $category->id)->name)->toBe('New category name');
});

it('never reuses the previous visitors CSRF token on a cache hit', function (): void {
    $alice = str_repeat('a', 40);
    $bob = str_repeat('b', 40);
    $this->withSession(['_token' => $alice])->get('/eventi')->assertHeader('X-Page-Cache', 'miss')->assertSee($alice);
    $this->withSession(['_token' => $bob])->get('/eventi')->assertHeader('X-Page-Cache', 'hit')
        ->assertSee($bob)->assertDontSee($alice);
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

it('caches map pages separately for all dates and price filters', function (): void {
    $city = app(CurrentCity::class)->get();
    occurrenceAtLocal($city, testCategory(), '2026-09-13 21:30');

    $this->get('/mappa')->assertOk()->assertHeader('X-Page-Cache', 'miss')->assertSee('data-result-count="1"', false);
    $this->get('/mappa')->assertHeader('X-Page-Cache', 'hit')->assertSee('data-result-count="1"', false);
    $this->get('/mappa?all_dates=1')->assertHeader('X-Page-Cache', 'miss')->assertSee('data-result-count="2"', false);
    $this->get('/mappa?all_dates=1')->assertHeader('X-Page-Cache', 'hit')->assertSee('data-result-count="2"', false);
    $this->get('/mappa?price=free')->assertHeader('X-Page-Cache', 'miss');
    $this->get('/mappa?price=free')->assertHeader('X-Page-Cache', 'hit');
});

it('reduces all_dates to on or absent so spellings and garbage share one entry', function (): void {
    $city = app(CurrentCity::class)->get();
    occurrenceAtLocal($city, testCategory(), '2026-09-13 21:30');
    $middleware = app(CachePage::class);
    $acceso = $middleware->key(Request::create('/mappa?all_dates=1'));
    $spento = $middleware->key(Request::create('/mappa'));

    expect($middleware->key(Request::create('/mappa?all_dates=true')))->toBe($acceso)
        ->and($middleware->key(Request::create('/mappa?all_dates=on')))->toBe($acceso)
        ->and($middleware->key(Request::create('/mappa?all_dates=zzz')))->toBe($spento)
        ->and($middleware->key(Request::create('/mappa?all_dates=0')))->toBe($spento)
        ->and($acceso)->not->toBe($spento);

    /* Le varianti scritte a mano leggono la voce già salvata invece di aprirne una. */
    $this->get('/mappa?all_dates=1')->assertHeader('X-Page-Cache', 'miss')->assertSee('data-result-count="2"', false);
    $this->get('/mappa?all_dates=true')->assertOk()->assertHeader('X-Page-Cache', 'hit')->assertSee('data-result-count="2"', false);
    $this->get('/mappa')->assertHeader('X-Page-Cache', 'miss')->assertSee('data-result-count="1"', false);
    $this->get('/mappa?all_dates=zzz')->assertOk()->assertHeader('X-Page-Cache', 'hit')->assertSee('data-result-count="1"', false);
});

it('reads all_dates=on as on, so the first spelling cannot store today under the all-dates entry', function (): void {
    $city = app(CurrentCity::class)->get();
    occurrenceAtLocal($city, testCategory(), '2026-09-13 21:30');

    /* Prima `on`: deve mostrare tutte le date, perché la sua voce di cache è quella di `1`. */
    $this->get('/mappa?all_dates=on')->assertOk()->assertHeader('X-Page-Cache', 'miss')->assertSee('data-result-count="2"', false);
    $this->get('/mappa?all_dates=1')->assertOk()->assertHeader('X-Page-Cache', 'hit')->assertSee('data-result-count="2"', false);
});

it('bypasses map page caching for dynamic requests', function (string $url, array $headers) {
    $this->get('/mappa')->assertHeader('X-Page-Cache', 'miss');
    $this->get($url, $headers)->assertOk()->assertHeaderMissing('X-Page-Cache');
})->with([
    'bounds' => ['/mappa?bbox=11,45,12,46', []],
    'position' => ['/mappa?lat=45.4&lng=11.8', []],
    'search' => ['/mappa?q=concerto', []],
    'ajax' => ['/mappa', ['X-Requested-With' => 'XMLHttpRequest']],
    'markers' => ['/mappa/marcatori', []],
]);

it('does not serve cached map pages to authenticated visitors', function () {
    $this->get('/mappa')->assertHeader('X-Page-Cache', 'miss');
    $this->actingAs(User::factory()->create())->get('/mappa')->assertOk()->assertHeaderMissing('X-Page-Cache');
});
