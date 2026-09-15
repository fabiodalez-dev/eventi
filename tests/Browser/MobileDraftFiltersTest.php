<?php

declare(strict_types=1);

it('shows filtered mobile events in the viewport in both themes', function (string $theme): void {
    $city = testCity();
    freezeLocal($city, '2026-09-14 12:00');
    occurrenceAtLocal($city, testCategory(), '2026-09-14 21:00');
    $page = visit('/eventi')->resize(390, 844);
    $page->script('document.documentElement.dataset.theme = "'.$theme.'"');
    $page->click('[data-consent-banner] button[value="reject_all"]')
        ->click('[data-catalog-filters] > summary');
    expect($page->script('getComputedStyle(document.querySelector("#filtri")).overscrollBehaviorY'))->toBe('auto');
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    $page->page()->locator('[data-catalog-filters] a[href*="date=tonight"]')->first()->click(['timeout' => 10000]);
    $page->assertPresent('[data-event-browser]:not([aria-busy]) a[aria-current="true"][href="'.url('/eventi').'"]');
    expect($page->script('location.search'))->toContain('date=tonight');
    expect($page->script('document.querySelector("[data-catalog-filters]").open'))->toBeFalse();
    expect($page->script('document.querySelector("[data-catalog-results]").getBoundingClientRect().top >= -1 && document.querySelector("[data-catalog-results]").getBoundingClientRect().top < innerHeight / 2'))->toBeTrue();
    expect($page->script('getComputedStyle(document.querySelector("[data-catalog-results]")).opacity'))->toBe('1');
    $page->assertVisible('[data-results] .event-card')->assertNoJavascriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
})->with(['light', 'dark']);
