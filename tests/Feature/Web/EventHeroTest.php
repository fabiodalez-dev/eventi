<?php

declare(strict_types=1);

use App\Support\Poster;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows a branded placeholder on event and occurrence pages without a poster', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $occurrence = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:00:00', event: ['poster' => null]);

    foreach ([route('events.show', $occurrence->event), route('events.occurrence', ['slug' => $occurrence->event->slug, 'occurrence' => $occurrence->id])] as $url) {
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
