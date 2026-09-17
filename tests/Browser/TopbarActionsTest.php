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
    $page->click('[data-topbar-more]')->assertSee('Biglietti e partecipanti')->assertSee('Apri il sito pubblico');
    if ($panel === 'admin') {
        $page->assertSee('Calendario');
    } else {
        $page->assertSee('Profilo del locale');
    }
    $page->screenshot(fullPage: false, filename: 'topbar-'.$panel.'-'.$width);
    $page->keys('[data-topbar-more]', 'Escape');
    $page->click('[data-topbar-action="create"]')->assertSee('Nuovo evento')->assertNoJavascriptErrors();
})->with([['admin', 1440], ['admin', 390], ['admin', 320], ['venue', 1440], ['venue', 390], ['venue', 320]]);
