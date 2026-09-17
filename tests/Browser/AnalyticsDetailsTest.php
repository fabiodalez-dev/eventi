<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\EventShareAnalytics;
use App\Filament\Venue\Pages\Dashboard;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use Filament\Facades\Filament;
use Tests\Support\VenueIsolationScenario;

it('navigates to separate event venue and organizer analytics pages with independent scopes', function (): void {
    $scenario = VenueIsolationScenario::make();
    $organizer = Organizer::create(['name' => 'Rete delle Arti', 'city_id' => $scenario->city->id, 'owner_id' => $scenario->ownerA->id, 'is_active' => true]);
    $scenario->publishedEventA->update(['organizer_id' => $organizer->id]);
    $scenario->publishedEventB->update(['organizer_id' => $organizer->id]);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    $this->actingAs($scenario->admin);
    $page = visit(EventShareAnalytics::getUrl(['filters' => ['venue' => $scenario->venueA->id, 'channel' => 'whatsapp']], panel: 'admin'))->resize(1440, 1000);
    $page->assertSee('Filtri attivi')->assertDontSee($scenario->publishedEventB->title);
    $page->click('[data-analytics-reset-filters]')->assertSee($scenario->publishedEventB->title);
    $page->page()->locator('a[href*="/event/'.$scenario->publishedEventA->id.'"]')->first()->click(['timeout' => 5000]);
    $page->assertSee('Analytics · '.$scenario->publishedEventA->title)->assertPresent('[data-analytics-subject="event"]');
    expect($page->script('location.pathname'))->toEndWith('/event/'.$scenario->publishedEventA->id);
    expect($page->script('scrollY'))->toBe(0);
    $page->page()->locator('a[href*="/venue/'.$scenario->venueA->id.'"]')->first()->click(['timeout' => 5000]);
    $page->assertSee('Analytics · Circolo Aurora')->assertSee('Report del profilo')->assertDontSee($scenario->publishedEventB->title);
    expect($page->script('location.pathname'))->toEndWith('/venue/'.$scenario->venueA->id);
    $page->page()->locator('a[href*="/organizer/'.$organizer->id.'"]')->first()->click(['timeout' => 5000]);
    $page->assertSee('Analytics · Rete delle Arti')->assertSee($scenario->publishedEventA->title)->assertSee($scenario->publishedEventB->title)->assertNoJavascriptErrors();
    expect($page->script('location.pathname'))->toEndWith('/organizer/'.$organizer->id);
    $page->screenshot(filename: 'analytics-organizer-detail-desktop');
});

it('lets a venue read all nights edit deliberately and open event statistics from its tabs', function (): void {
    $scenario = VenueIsolationScenario::make();
    EventOccurrence::factory()->create(['event_id' => $scenario->publishedEventA->id, 'starts_at' => '2026-10-11 19:00:00', 'ends_at' => '2026-10-11 21:00:00', 'highlight' => 'Seconda serata speciale']);
    Filament::setCurrentPanel('venue');
    $this->actingAs($scenario->ownerA);
    Filament::setTenant($scenario->venueA);
    $page = visit(Dashboard::getUrl(panel: 'venue', tenant: $scenario->venueA))->resize(390, 844)
        ->assertSee('Circolo Aurora')->assertSee('Report del profilo')->assertPresent('[data-analytics-summary]')->assertPresent('[data-analytics-trend]');
    $page->navigate(EventResource::getUrl('index', panel: 'venue', tenant: $scenario->venueA));
    $view = EventResource::getUrl('view', ['record' => $scenario->publishedEventA], panel: 'venue', tenant: $scenario->venueA);
    $page->assertVisible('a.fi-ta-record-content[href$="'.parse_url($view, PHP_URL_PATH).'"]');
    $page->page()->locator('a.fi-ta-record-content[href$="'.parse_url($view, PHP_URL_PATH).'"]')->first()->click(['timeout' => 5000]);
    $page->assertPresent('[data-event-read-view]')->assertSee('Seconda serata speciale')->assertSee('11/09/2026')->assertSee('11/10/2026');
    expect($page->script('document.querySelectorAll("[data-event-read-view] input, [data-event-read-view] textarea").length'))->toBe(0);
    $page->page()->locator('.fi-page-sub-navigation-tabs a[href*="/event/'.$scenario->publishedEventA->id.'"]')->first()->click(['timeout' => 5000]);
    $page->assertPresent('[data-analytics-subject="event"]')->assertSee('Analytics · '.$scenario->publishedEventA->title);
    $page->assertVisible('.fi-page-sub-navigation-tabs a[href$="'.parse_url($view, PHP_URL_PATH).'"]');
    $page->page()->locator('.fi-page-sub-navigation-tabs a[href$="'.parse_url($view, PHP_URL_PATH).'"]')->first()->click(['timeout' => 5000]);
    $page->assertPresent('[data-event-read-view]');
    $page->screenshot(filename: 'venue-event-view-mobile');
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    expect($page->script('Array.from(document.querySelectorAll("[data-event-read-view] > *, [data-event-read-view] .ad-table-scroll")).every(el => el.getBoundingClientRect().right <= innerWidth)'))->toBeTrue();
    $edit = EventResource::getUrl('edit', ['record' => $scenario->publishedEventA], panel: 'venue', tenant: $scenario->venueA);
    $page->assertVisible('.fi-page-sub-navigation-tabs a[href$="'.parse_url($edit, PHP_URL_PATH).'"]');
    $page->page()->locator('.fi-page-sub-navigation-tabs a[href$="'.parse_url($edit, PHP_URL_PATH).'"]')->first()->click(['timeout' => 5000]);
    $page->assertMissing('[data-event-read-view]')->assertSee('Salva')->assertNoJavascriptErrors();
    expect($page->script('location.pathname'))->toEndWith('/modifica');
});
