<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\EventShareAnalytics;
use App\Filament\Venue\Pages\Statistics;
use App\Models\EventShareLink;
use App\Services\Analytics\EventShares;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\Support\VenueIsolationScenario;

it('copies a short event link and preserves the event page after following it', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-10 12:00');
    $occurrence = occurrenceAtLocal($city, testCategory(), '2026-09-11 21:00');
    $page = visit('/eventi/'.$occurrence->event->slug.'/1')
        ->withUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36')
        ->click('[data-consent-banner] button[value="accept_all"]')
        ->assertMissing('[data-consent-banner]');
    // The OS share sheet is external UI; exercise the real handler with a
    // successful clipboard implementation and verify its payload, not delivery.
    $page->script('Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText: async value => { window.__copiedShare = value; } } });');
    $page->script('const originalFetch = window.fetch; window.fetch = async (...args) => { const response = await originalFetch(...args); if (String(args[0]).endsWith("/share")) window.__shareStatus = response.status; return response; };');
    $page->page()->locator('[data-share]')->first()->click(['timeout' => 5000]);
    $page->assertSee('Link copiato')->assertScript('window.__shareStatus', 204)->assertNoJavascriptErrors();
    expect((int) DB::table('event_share_daily')->sum('shares'))->toBe(1);
    $page->script('window.__shareStatus = null; document.querySelector("[data-share-channel=whatsapp]").addEventListener("click", event => event.preventDefault());');
    $page->page()->locator('[data-share-channel=whatsapp]')->first()->click(['timeout' => 5000]);
    $page->assertScript('window.__shareStatus', 204);
    expect((int) DB::table('event_share_daily')->sum('shares'))->toBe(2);
    expect((int) DB::table('event_views_daily')->sum('shares'))->toBe(2);
    expect((int) DB::table('event_views_daily')->sum('website_clicks'))->toBe(0);
    $url = $page->script('window.__copiedShare');
    expect(parse_url($url, PHP_URL_PATH))->toMatch('/^\/s\/[A-Za-z0-9]{7}$/');
    $destination = $page->navigate($url)->assertSee($occurrence->event->title)->assertNoJavascriptErrors();
    expect((int) DB::table('event_share_daily')->sum('clicks'))->toBe(1);
    expect($destination->script('location.pathname'))->toBe('/eventi/'.$occurrence->event->slug.'/1');
    expect($destination->script('document.querySelector("link[rel=canonical]").href'))->toContain('/eventi/'.$occurrence->event->slug.'/1');
});

it('shows short-link results in venue and admin analytics without overflowing mobile screens', function (string $panel): void {
    $scenario = VenueIsolationScenario::make();
    app(EventShares::class)->links($scenario->publishedEventA, $scenario->occurrenceA);
    $link = EventShareLink::where('channel', 'whatsapp')->firstOrFail();
    app(EventShares::class)->record($link, true);
    app(EventShares::class)->record($link, false);
    Filament::setCurrentPanel($panel);
    $this->actingAs($panel === 'admin' ? $scenario->admin : $scenario->ownerA);
    $tenant = $panel === 'admin' ? null : $scenario->venueA;
    Filament::setTenant($tenant);
    $url = $panel === 'admin' ? EventShareAnalytics::getUrl(panel: 'admin') : Statistics::getUrl(panel: 'venue', tenant: $tenant);
    $page = visit($url)->resize(390, 844)->assertSee('Analytics condivisioni')
        ->assertPresent('[data-event-share-report]')->assertSee('/s/'.$link->code)
        ->click('7 giorni')->assertSee('Aperture del link')->assertNoJavascriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    expect(DB::table('event_share_daily')->sum('clicks'))->toBe('1');
})->with(['venue', 'admin']);
