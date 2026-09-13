<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Services\Seo\StructuredData;

it('keeps pagination canonical and strips marketing parameters on taxonomy pages', function (): void {
    $city = testCity();
    $category = testCategory();
    occurrenceAtLocal($city, $category, '2026-09-12 21:30');
    freezeLocal($city, '2026-09-01 12:00');
    $url = route('events.category', $category);
    $this->get($url.'?page=2&utm_source=social')->assertOk()
        ->assertSee('<link rel="canonical" href="'.$url.'?page=2">', false)
        ->assertSee('CollectionPage');
});

it('does not invent event end times and does not advertise cancelled tickets as available', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-01 12:00');
    $category = testCategory();
    $date = occurrenceAtLocal($city, $category, '2026-09-12 21:30', event: ['price_type' => PriceType::Free],
        occurrence: ['ends_at' => null, 'status' => OccurrenceStatus::Cancelled]);
    $node = app(StructuredData::class)->event($date->event, $date);
    expect($node)->not->toHaveKey('endDate')
        ->and($node['offers']['availability'])->toBe('https://schema.org/Discontinued');
});

it('gives the home a descriptive title and a canonical without tracking parameters', function (): void {
    $city = testCity();
    $this->get('/?utm_source=instagram')->assertOk()
        ->assertSee(__('seo.home_title', ['city' => $city->name]))
        ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
        ->assertSee('max-image-preview:large');
});
