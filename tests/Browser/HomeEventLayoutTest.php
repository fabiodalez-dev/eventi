<?php

declare(strict_types=1);

use App\Models\Venue;
use App\Support\EventUrl;

it('balances mobile shortcuts, actions and statistics', function (string $theme): void {
    $city = testCity();
    freezeLocal($city, '2026-09-12 12:00');
    $category = testCategory();
    foreach (['2026-09-12 21:00', '2026-09-13 21:00'] as $when) {
        occurrenceAtLocal($city, $category, $when, event: ['price_type' => 'free']);
    }
    $page = visit('/')->{$theme}()->resize(391, 734)
        ->click('[data-consent-banner] button[value="reject_all"]');
    expect($page->script('() => { const boxes = [...document.querySelectorAll(".home-shortcuts a")].map(e => e.getBoundingClientRect()); return boxes.length === 4 && boxes[0].top === boxes[1].top && boxes[2].top === boxes[3].top && boxes[2].top > boxes[0].top; }'))->toBeTrue();
    expect($page->script('() => { const boxes = [...document.querySelectorAll(".home-actions a")].map(e => e.getBoundingClientRect()); return boxes[0].top === boxes[1].top && boxes[2].top > boxes[0].top && Math.abs(boxes[2].width - (boxes[1].right - boxes[0].left)) < 2 && document.documentElement.scrollWidth <= innerWidth; }'))->toBeTrue();
    if ($theme === 'inLightMode') {
        expect($page->script('getComputedStyle(document.querySelector(".home-stats > div")).alignItems'))->toBe('center');
    }
    expect($page->script('parseFloat(getComputedStyle(document.querySelector("[data-home-venue]")).fontSize) >= 16'))->toBeTrue();
    $page->screenshot(filename: 'home-balanced-'.$theme);
    $page->resize(666, 734);
    expect($page->script('() => { const b=[...document.querySelectorAll(".home-shortcuts a")].map(e=>e.getBoundingClientRect()); return b[0].top === b[1].top && b[2].top > b[0].top && b[2].top === b[3].top; }'))->toBeTrue();
})->with(['inLightMode', 'inDarkMode']);

it('keeps the complete portrait poster beside readable event information', function (string $theme, int $width): void {
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id, 'name' => 'Locale visibile']);
    $date = occurrenceAtLocal($city, testCategory(), now('Europe/Rome')->addDay()->format('Y-m-d').' 21:00', event: ['title' => 'Concerto in evidenza', 'poster' => '/icon-512.png'], venue: $venue);
    $page = visit(EventUrl::occurrence($date))->{$theme}()->resize($width, 900)
        ->click('[data-consent-banner] button[value="reject_all"]')->assertSee('Locale visibile');
    expect($page->script('() => { const hero=document.querySelector(".event-detail-hero"); const frame=hero.querySelector(".event-poster-frame").getBoundingClientRect(); const heading=hero.querySelector(".event-heading-panel").getBoundingClientRect(); const img=hero.querySelector("img"); return Math.abs(frame.width / frame.height - .75) < .01 && frame.right <= heading.left + 2 && getComputedStyle(img).objectFit === "contain" && document.documentElement.scrollWidth <= innerWidth; }'))->toBeTrue();
    $page->screenshot(filename: 'event-hero-'.$theme.'-'.$width);
})->with(['inLightMode', 'inDarkMode'])->with([804, 1440]);
