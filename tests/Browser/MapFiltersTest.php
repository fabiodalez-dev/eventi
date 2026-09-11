<?php

declare(strict_types=1);

use App\Models\Venue;

it('preserves the map and sidebar while updating dependent options, search and history', function (string $device): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-11 12:00');
    foreach (['Padova', 'Abano Terme'] as $town) {
        $venue = Venue::factory()->approved()->create(['city_id' => $city->id, 'name' => 'Locale '.$town, 'municipality' => $town]);
        occurrenceAtLocal($city, $category, '2026-09-11 21:00', event: ['content_details' => ['membership' => 'required']], venue: $venue);
    }
    $page = visit('/mappa?all_dates=1')->inLightMode()->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')
        ->click('[data-filter-key="advanced"] summary');
    $page->script('window.originalForm = document.querySelector("aside form"); window.originalMap = document.querySelector("[data-map-shell]"); window.originalTown = document.querySelector("[name=municipality]");');
    $page->fill('#campo-municipality-search', 'pad');
    expect($page->script('document.querySelector("#campo-municipality-search").value'))->toBe('pad');
    $page->keys('#campo-municipality-search', ['ArrowDown', 'Enter'])
        ->assertPresent('[data-event-browser]:not([aria-busy]) [name="municipality"] option[value="Padova"][selected]');
    expect($page->script('window.originalForm === document.querySelector("aside form") && window.originalMap === document.querySelector("[data-map-shell]") && window.originalTown === document.querySelector("[name=municipality]")'))->toBeTrue();
    expect($page->script('document.querySelector("aside details").open'))->toBeTrue();
    expect($page->script('document.querySelector("#campo-municipality-search").value'))->toBe('pad');
    expect($page->script('Array.from(document.querySelector("[name=venue]").options).map(o => o.textContent).join("|")'))->toContain('Locale Padova')->not->toContain('Locale Abano Terme');
    $page->fill('#campo-municipality-search', 'nessun-comune');
    $page->assertVisible('[data-filter-key="field-municipality"] [data-option-search-empty]');
    $page->fill('#campo-municipality-search', '');
    $page->script('history.back()');
    $page->assertPresent('[data-event-browser]:not([aria-busy]) [name="municipality"]:not(:has(option[selected]))');
    expect($page->script('Array.from(document.querySelector("[name=venue]").options).map(o => o.textContent).join("|")'))->toContain('Locale Padova')->toContain('Locale Abano Terme');
    $page->select('membership', 'required')->assertPresent('[data-event-browser]:not([aria-busy]) [name="membership"] option[value="required"][selected]');
    expect($page->script('document.querySelector("aside details").open'))->toBeTrue();
    // A failed request leaves controls usable and reports the failure inline.
    $page->script('(() => { window.fetch = async () => { throw new TypeError("offline") }; return true; })()');
    $page->select('membership', 'not_required')->assertVisible('[data-filter-status]:not([hidden])');
    expect($page->script('document.querySelector("[name=membership]").value'))->toBe('required');
    expect($page->script('document.querySelector("aside details").open'))->toBeTrue();
})->with(['desktop', 'mobile']);
