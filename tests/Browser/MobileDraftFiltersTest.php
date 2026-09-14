<?php

declare(strict_types=1);

it('applies mobile catalogue choices only after confirmation', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-14 12:00');
    occurrenceAtLocal($city, testCategory(), '2026-09-14 21:00');
    $page = visit('/eventi')->resize(390, 844);
    $page->click('[data-consent-banner] button[value="reject_all"]')
        ->click('[data-catalog-filters] > summary');
    $page->page()->locator('[data-catalog-filters] a[href*="date=today"]')->first()->click(['timeout' => 10000]);
    expect($page->script('location.search'))->toBe('');
    $page->assertPresent('[data-filters-pending]');
    $page->page()->locator('[data-filter-close]')->click(['timeout' => 10000]);
    $page->assertPresent('[data-event-browser]:not([aria-busy])');
    expect($page->script('location.search'))->toContain('date=today');
});
