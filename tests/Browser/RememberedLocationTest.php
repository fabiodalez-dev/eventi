<?php

use App\Models\Venue;

it('remembers and forgets the approximate location through the real home controls', function (string $theme): void {
    $city = testCity();
    freezeLocal($city, '2026-09-15 12:00');
    $venue = Venue::factory()->approved()->at(45.41, 11.88)->create(['city_id' => $city->id]);
    occurrenceAtLocal($city, testCategory(), '2026-09-16 20:00', venue: $venue);
    occurrenceAtLocal($city, testCategory(), '2026-09-15 15:00', venue: $venue);
    $page = visit('/')->{$theme}()->resize(391, 844);
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');
    expect($page->script('document.querySelector("#sezione-today").closest("section").nextElementSibling.matches("[data-home-nearby]")'))->toBeTrue();
    $page->select('[data-nearby-radius]', '10');
    $page->assertQueryStringHas('nearby_radius', '10');
    $page->waitForEvent('domcontentloaded');
    expect($page->script('new URL(location.href).searchParams.get("nearby_radius")'))->toBe('10');
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');
    $page->script(<<<'JS'
        Object.defineProperty(navigator, 'geolocation', { configurable: true, value: {
            getCurrentPosition: success => success({ coords: { latitude: 45.406733, longitude: 11.876814 } })
        } });
        JS);
    $page->click('[data-location-use]')->assertAttribute('[data-remembered-location]', 'data-position', '45.41,11.88');
    expect($page->script('document.querySelector("[data-remembered-location]").dataset.position'))->toBe('45.41,11.88');
    expect($page->script('document.cookie.includes("incitta_location")'))->toBeFalse();
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');
    $page->click('[data-location-forget]')->assertAttribute('[data-remembered-location]', 'data-position', '');
    expect($page->script('document.querySelector("[data-remembered-location]").dataset.position'))->toBe('');
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
})->with(['inLightMode', 'inDarkMode']);
