<?php

declare(strict_types=1);

use App\Models\Venue;
use App\Support\EventUrl;
use Tests\Support\ImageFixtures;

it('shows complete portrait and landscape artwork in equal portrait slots across listings', function (string $theme, int $width): void {
    config(['filesystems.disks.public.url' => '/storage']);
    if (! is_link(public_path('storage'))) {
        $this->artisan('storage:link')->assertSuccessful();
    }
    $city = testCity();
    freezeLocal($city, '2026-09-12 12:00');
    $category = testCategory();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $dates = [];
    foreach ([[300, 400], [600, 300], null] as $i => $size) {
        $date = occurrenceAtLocal($city, $category, '2026-09-12 21:00', event: ['title' => 'Locandina di prova '.$i, 'poster' => null], venue: $venue);
        if ($size !== null) {
            $date->event->addMedia(ImageFixtures::upload('poster.png', ImageFixtures::png(...$size)))->toMediaCollection('poster');
        }
        $dates[] = $date;
    }
    foreach (['/', '/eventi', '/mappa?all_dates=1', EventUrl::occurrence($dates[0]), '/locali/'.$venue->slug] as $path) {
        $page = visit($path)->{$theme}()->resize($width, 900);
        $page->script('document.querySelector("[data-consent-banner]")?.remove()');
        expect($page->script('async () => { const cards=[...document.querySelectorAll(".event-card")]; if (!cards.length) return false; for (const card of cards) { const frame=card.querySelector(".event-poster-frame"); if (!frame) return false; const r=frame.getBoundingClientRect(); if (Math.abs(r.width/r.height-.75)>.01) return false; const img=frame.querySelector("img"); if (img) { await img.decode(); if (!img.naturalWidth || getComputedStyle(img).objectFit!=="contain") return false; } } return document.documentElement.scrollWidth<=innerWidth; }'))->toBeTrue();
        if ($path === '/eventi') {
            expect($page->script('() => { const frames=[...document.querySelectorAll(".event-card .event-poster-frame")].map(e=>e.getBoundingClientRect()); return frames.length>=3 && Math.max(...frames.map(r=>r.height))-Math.min(...frames.map(r=>r.height))<2; }'))->toBeTrue();
            $page->screenshot(filename: 'portrait-posters-'.$theme.'-'.$width);
        }
    }
})->with(['inLightMode', 'inDarkMode'])->with([391, 1280]);
