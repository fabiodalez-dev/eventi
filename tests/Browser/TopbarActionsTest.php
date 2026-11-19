<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\EventShareAnalytics;
use App\Filament\Venue\Resources\Events\EventResource;
use Filament\Facades\Filament;
use Tests\Support\VenueIsolationScenario;

it('keeps role-specific topbar shortcuts usable on desktop and small phones', function (string $panel, int $width, string $appearance): void {
    $scenario = VenueIsolationScenario::make();
    $user = $panel === 'admin' ? $scenario->admin : $scenario->ownerA;
    /* Il pannello segue il tema scelto sul sito: le scorciatoie devono
       restare leggibili e cliccabili in entrambi. */
    $user->forceFill(['appearance' => $appearance])->save();
    $this->actingAs($user->fresh());
    Filament::setCurrentPanel($panel);
    Filament::setTenant($panel === 'venue' ? $scenario->venueA : null);
    $url = $panel === 'admin' ? EventShareAnalytics::getUrl()
        : EventResource::getUrl();
    $page = visit($url)->resize($width, 900);
    $page->assertSee($panel === 'admin' ? 'Amministrazione' : 'Circolo Aurora')
        ->assertVisible('[data-topbar-action="create"]')
        ->assertVisible('[data-topbar-action="analytics"]');
    expect($page->script('Array.from(document.querySelectorAll(".itb-actions > *, .itb-context, .fi-topbar-end")).every(el => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; })'))->toBeTrue();
    expect($page->script('document.documentElement.classList.contains("dark")'))->toBe($appearance === 'dark');
    /* Testata scura nel tema scuro, chiara nel chiaro: luminanza del fondo,
       letta da un pixel di canvas perché il colore calcolato può essere oklch. */
    $luminance = $page->script('(() => { const c = document.createElement("canvas").getContext("2d"); c.fillStyle = getComputedStyle(document.querySelector(".fi-topbar")).backgroundColor; c.fillRect(0, 0, 1, 1); const [r, g, b] = c.getImageData(0, 0, 1, 1).data; return 0.2126 * r + 0.7152 * g + 0.0722 * b; })()');
    expect($appearance === 'dark' ? $luminance < 60 : $luminance > 200)->toBeTrue();
    $page->click('[data-topbar-action="analytics"]')->assertPresent('[data-analytics-summary]');
    /* Filament 5.10 dichiara il menu con `aria-haspopup="menu"` (prima era
       `"true"`): conta che l'attributo ci sia, non il valore esatto. */
    $page->assertVisible('[data-topbar-more][aria-haspopup]');
    $page->page()->locator('[data-topbar-more]')->click(['timeout' => 5000]);
    $page->assertVisible('[data-topbar-more][aria-expanded="true"]')
        ->assertVisible('[data-topbar-action="tickets"]')->assertVisible('[data-topbar-action="public"]');
    if ($panel === 'admin') {
        $page->assertSee('Calendario');
    } else {
        $page->assertSee('Profilo del locale');
    }
    $page->screenshot(fullPage: false, filename: 'topbar-'.$panel.'-'.$width.'-'.$appearance);
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
})->with([
    ['admin', 1440, 'light'], ['admin', 390, 'light'], ['admin', 320, 'light'],
    ['venue', 1440, 'light'], ['venue', 390, 'light'], ['venue', 320, 'light'],
    ['admin', 1440, 'dark'], ['admin', 390, 'dark'], ['venue', 390, 'dark'], ['venue', 320, 'dark'],
]);
