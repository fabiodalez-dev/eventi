<?php

declare(strict_types=1);

use App\Models\Venue;
use Tests\Support\ImageFixtures;

it('shows one follow action and the actual uncropped venue logo', function (string $device): void {
    config(['filesystems.disks.public.url' => '/storage']);
    if (! is_link(public_path('storage'))) {
        $this->artisan('storage:link')->assertSuccessful();
    }
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id, 'name' => 'Circolo Noi Este', 'is_verified' => true, 'is_nonprofit' => true, 'requires_membership' => true]);
    $venue->addMedia(ImageFixtures::upload('logo.png', ImageFixtures::png(600, 240)))->toMediaCollection('logo');
    $page = visit('/locali/'.$venue->slug)->inLightMode()->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')->assertVisible('[data-venue-header] img');
    expect($page->script('async () => { const h=document.querySelector("[data-venue-header]"); const img=h.querySelector("img"); await img.decode(); return img.naturalWidth > 0 && getComputedStyle(img).objectFit === "contain" && h.querySelectorAll("a[href*=intended]").length === 1 && document.documentElement.scrollWidth <= innerWidth; }'))->toBeTrue();
    $page->screenshot(filename: 'venue-header-'.$device);
    if ($device === 'mobile') {
        $page->resize(666, 734);
        expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    }
})->with(['desktop', 'mobile']);

it('opens filters above the catalog and displays posters after filtering', function (string $device): void {
    $city = testCity();
    $category = testCategory();
    config(['eventi.per_page' => 2]);
    for ($i = 0; $i < 10; $i++) {
        occurrenceAtLocal($city, $category, now('Europe/Rome')->addDay()->format('Y-m-d').' 21:00', event: ['title' => 'Concerto con locandina '.$i, 'poster' => '/icon-192.png']);
    }
    $page = visit('/eventi?page=5')->inLightMode()->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')->assertSee('Concerto con locandina')->assertVisible('[data-catalog-poster] img');
    if ($device === 'mobile') {
        expect($page->script('document.querySelector("[data-catalog-filters]").open'))->toBeFalse();
        $page->click('[data-filter-jump]')->assertVisible('[data-filter-panel]');
        expect($page->script('document.querySelector("#filtri").getBoundingClientRect().top < innerHeight && document.querySelector("#filtri").getBoundingClientRect().top < document.querySelector("[data-results]").getBoundingClientRect().top'))->toBeTrue();
    }
    $page->click('[data-filter-key="advanced"] > summary')->select('sort', 'relevance')->assertQueryStringHas('sort', 'relevance')->assertVisible('[data-filter-panel]');
    if ($device === 'mobile') {
        $page->click('[data-filter-close]');
        expect($page->script('document.querySelector("[data-catalog-filters]").open'))->toBeFalse();
        $page->resize(493, 734);
    }
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    $page->assertVisible('[data-catalog-poster] img')->screenshot(filename: 'catalog-posters-'.$device);
})->with(['desktop', 'mobile']);
