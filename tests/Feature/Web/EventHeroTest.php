<?php

declare(strict_types=1);

use App\Models\EventOccurrence;
use App\Support\EventUrl;
use App\Support\Poster;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('does not link a single date back to itself', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:00:00');

    foreach ([route('events.show', $date->event), EventUrl::occurrence($date)] as $url) {
        $this->get($url)->assertOk()->assertDontSee(__('seo.date_page'));
    }
});

it('keeps date links on a series even when only one date is displayed', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $first = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:00:00');
    EventOccurrence::factory()->create([
        'event_id' => $first->event_id,
        'starts_at' => localInstant($city, '2026-09-11 21:00:00')->utc(),
        'ends_at' => null,
    ]);
    config(['eventi.dates_shown' => 1]);

    $this->get(route('events.show', $first->event))->assertOk()->assertSee(__('seo.date_page'));
});

it('shows one shared description immediately after saving the date', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $item = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:00:00', event: [
        'poster' => '/storage/poster-test.jpg', 'description' => 'Descrizione completa da leggere subito.',
    ]);
    $html = $this->get(route('events.show', $item->event))->assertOk()->getContent();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $description = $xpath->query('//article/section[@aria-labelledby="descrizione-evento"]');
    expect($description->length)->toBe(1)
        ->and($description->item(0)->textContent)->toContain('Descrizione completa da leggere subito.');
    expect($xpath->query('//article/section[@aria-labelledby="descrizione-evento"]/preceding-sibling::*[1][@aria-labelledby="salva-evento"]')->length)->toBe(1);
});

it('shows a branded placeholder on event and occurrence pages without a poster', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $occurrence = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:00:00', event: ['poster' => null]);

    foreach ([route('events.show', $occurrence->event), EventUrl::occurrence($occurrence)] as $url) {
        $this->get($url)->assertOk()
            ->assertSee('data-event-hero-placeholder', false)
            ->assertSee(__('events.card.poster_missing'))
            ->assertDontSee('data-poster-reveal', false);
    }
});

it('reveals the hero in color without changing the monochrome default of previews', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $occurrence = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:00:00', event: ['poster' => '/storage/poster-test.jpg']);

    $html = $this->get(route('events.show', $occurrence->event))->assertOk()
        ->assertDontSee('data-event-hero-placeholder', false)->getContent();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $hero = $xpath->query('//img[@data-poster-reveal and @loading="eager"]')->item(0);

    expect($hero)->not->toBeNull()
        ->and($hero->getAttribute('class'))->toContain('poster-reveal')->not->toContain('grayscale-photo');

    $this->blade('<x-media-image :set="$set" alt="Poster" width="800" height="600" />', [
        'set' => Poster::imageSet($occurrence->event),
    ])->assertSee('grayscale-photo', false)->assertDontSee('data-poster-reveal', false);
});
