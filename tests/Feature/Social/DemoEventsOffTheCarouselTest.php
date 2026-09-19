<?php

declare(strict_types=1);

use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Social\SocialCatalog;
use Carbon\Carbon;

/**
 * Il catalogo del carosello — quello di `social:daily` e del riquadro «oggi»
 * del pannello — non contiene eventi dimostrativi: sui social l'avviso della
 * scheda non c'è, e un appuntamento inventato sembrerebbe vero. Chi ne
 * sceglie uno per identificativo dallo studio lo sta chiedendo apposta.
 */
afterEach(fn () => Carbon::setTestNow());

it('lascia fuori gli eventi dimostrativi dal catalogo automatico, non da una scelta esplicita', function (): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-10 12:00');
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $demo = occurrenceAtLocal($city, $category, '2026-09-10 21:00', event: ['is_demo' => true, 'title' => 'Serata dimostrativa'], venue: $venue);
    $real = occurrenceAtLocal($city, $category, '2026-09-10 22:00', event: ['title' => 'Serata vera'], venue: $venue);
    $today = EventOccurrenceQuery::for($city)->currentBusinessDate();

    expect(app(SocialCatalog::class)->events($city, $today)->pluck('id')->all())->toBe([$real->id])
        ->and(app(SocialCatalog::class)->events($city, $today, $venue->id)->pluck('id')->all())->toBe([$real->id])
        ->and(app(SocialCatalog::class)->events($city, $today, null, $demo->event_id)->pluck('id')->all())->toBe([$demo->id]);
});
