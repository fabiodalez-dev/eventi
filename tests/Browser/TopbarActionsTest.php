<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\EventShareAnalytics;
use App\Filament\Venue\Resources\Events\EventResource;
use Filament\Facades\Filament;
use Tests\Support\VenueIsolationScenario;

it('keeps role-specific topbar shortcuts usable on desktop and small phones', function (string $panel, int $width): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($panel === 'admin' ? $scenario->admin : $scenario->ownerA);
    Filament::setCurrentPanel($panel);
    Filament::setTenant($panel === 'venue' ? $scenario->venueA : null);
    $url = $panel === 'admin' ? EventShareAnalytics::getUrl()
        : EventResource::getUrl();
    $page = visit($url)->resize($width, 900);
    $page->assertSee($panel === 'admin' ? 'Amministrazione' : 'Circolo Aurora')
        ->assertVisible('[data-topbar-action="create"]')
        ->assertVisible('[data-topbar-action="analytics"]');
    expect($page->script('Array.from(document.querySelectorAll(".itb-actions > *, .itb-context, .fi-topbar-end")).every(el => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; })'))->toBeTrue();
    $page->click('[data-topbar-action="analytics"]')->assertPresent('[data-analytics-summary]');
    $page->assertVisible('[data-topbar-more][aria-haspopup="true"]');
    $page->page()->locator('[data-topbar-more]')->click(['timeout' => 5000]);
    $page->assertVisible('[data-topbar-more][aria-expanded="true"]')
        ->assertVisible('[data-topbar-action="tickets"]')->assertVisible('[data-topbar-action="public"]');
    if ($panel === 'admin') {
        $page->assertSee('Calendario');
    } else {
        $page->assertSee('Profilo del locale');
    }
    $page->screenshot(fullPage: false, filename: 'topbar-'.$panel.'-'.$width);
    $page->keys('[data-topbar-more]', 'Escape');
    $page->click('[data-topbar-action="create"]')->assertSee('Nuovo evento');
    /*
     * Non `assertNoJavascriptErrors()`: su queste pagine il pannello carica gli
     * script di Filament, e i loro osservatori di ridimensionamento fanno
     * comparire a intermittenza «ResizeObserver loop completed with undelivered
     * notifications» — una nota sui tempi, non un'eccezione. Ha già fatto
     * cadere il job browser e fermato un rilascio, su una prova che riguarda le
     * scorciatoie della testata. Tutto il resto resta un errore.
     */
    expect(erroriJavascriptVeri($page))->toBe([]);
})->with([['admin', 1440], ['admin', 390], ['admin', 320], ['venue', 1440], ['venue', 390], ['venue', 320]]);
