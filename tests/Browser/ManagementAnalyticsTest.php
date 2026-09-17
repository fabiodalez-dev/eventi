<?php

declare(strict_types=1);

use App\Enums\ContentMetric;
use App\Filament\Organizer\Pages\Statistics as OrganizerStatistics;
use App\Filament\Venue\Pages\Statistics;
use App\Models\Organizer;
use App\Services\Analytics\RecordContentMetric;
use Filament\Facades\Filament;
use Tests\Support\VenueIsolationScenario;

it('shows usable analytics to venue and organizer managers on narrow screens', function (string $panel): void {
    $scenario = VenueIsolationScenario::make();
    $owner = $scenario->ownerA;
    $tenant = $scenario->venueA;
    $pageClass = Statistics::class;
    if ($panel === 'organizer') {
        $tenant = Organizer::create(['name' => 'Collettivo Aurora', 'city_id' => $scenario->city->id, 'owner_id' => $owner->id, 'is_active' => true]);
        $scenario->publishedEventA->update(['organizer_id' => $tenant->id]);
        $pageClass = OrganizerStatistics::class;
    }
    $this->actingAs($owner);
    Filament::setCurrentPanel($panel);
    Filament::setTenant($tenant);
    app(RecordContentMetric::class)->record('event', $scenario->publishedEventA->id, ContentMetric::Views);
    app(RecordContentMetric::class)->record($panel, $tenant->id, ContentMetric::Views);
    $page = visit($pageClass::getUrl(panel: $panel, tenant: $tenant))->resize(390, 844)
        ->assertSee('Analytics')->assertSee('Pagina del profilo')->assertSee($scenario->publishedEventA->title)
        ->assertPresent('[data-analytics-chart] svg')->assertPresent('[data-analytics-bars]')
        ->click('7 giorni')->assertSee('Ultimi 7 giorni, nel fuso della città.')
        ->assertNoJavascriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    $page->screenshot(filename: 'analytics-'.$panel.'-mobile');
})->with(['venue', 'organizer']);

it('refreshes the saved panel and its count after removing a guest save', function (bool $busyBrowser): void {
    $city = testCity();
    freezeLocal($city, '2026-09-10 12:00');
    $occurrence = occurrenceAtLocal($city, testCategory(), '2026-09-11 21:00');
    $page = visit('/eventi')->resize(1280, 900)
        ->click('[data-consent-banner] button[value="reject_all"]')
        ->assertMissing('[data-consent-banner]');
    // Use Playwright's single action: Pest retries actions after one second,
    // which can repeat a completed toggle or try to close an already closed dialog.
    $page->page()->locator('[data-save-id="'.$occurrence->id.'"] [data-save-button][data-save-variant="icon"]')->click(['timeout' => 5000]);
    $page->assertVisible('[data-save-prompt][open]');
    if ($busyBrowser) {
        // Reproduce a browser finishing the click after Pest's retry window.
        $page->script('document.querySelector("[data-save-prompt-dismiss]").addEventListener("click", () => { const deadline = performance.now() + 1200; while (performance.now() < deadline) {} })');
    }
    $page->page()->locator('[data-save-prompt-dismiss]')->click(['timeout' => 5000]);
    $page->assertMissing('[data-save-prompt][open]');
    $page->script('window.scrollTo(0, 0)');
    $page->assertVisible('[data-saved-opener]')->click('[data-saved-opener]')
        ->assertVisible('[data-saved-dialog] [data-saved-row="'.$occurrence->id.'"]');
    $page->page()->locator('[data-saved-dialog] [data-save-button]')->click(['timeout' => 5000]);
    $page->assertSee(__('account.saved.empty_title'))
        ->assertNoJavascriptErrors();
    expect($page->script('document.querySelector("[data-saved-opener-count]").textContent'))->toBe('0');
})->with(['normal browser' => false, 'delayed click completion' => true]);
