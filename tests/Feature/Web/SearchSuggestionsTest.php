<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\Tag;
use App\Models\Venue;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-05 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('starts at three characters and returns an escaped fragment rather than a full page', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-06 21:00:00', event: ['title' => 'Concertone <script>alert(1)</script>']);
    $this->get('/cerca/suggerimenti?q=co')->assertOk()->assertDontSee('Concertone');
    $this->get('/cerca/suggerimenti?q=con')->assertOk()
        ->assertSee('Concertone &lt;script&gt;', false)
        ->assertDontSee('<script>', false)->assertDontSee('<html', false)
        ->assertHeader('X-Robots-Tag', 'noindex');
});

it('finds partial words in event and venue descriptions and approved linked tags', function (): void {
    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->id, 'name' => 'Sala del quartiere',
        'description' => 'Un palcoscenico accogliente',
    ]);
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-06 21:00:00', event: [
        'title' => 'Serata strumentale', 'description' => 'Il contrabbasso incontra la chitarra',
    ], venue: $venue);
    $tag = Tag::factory()->create(['name' => 'Improvvisazione', 'is_approved' => true]);
    $date->event->tags()->attach($tag);
    foreach (['contra', 'palcos', 'improv'] as $term) {
        $this->get('/cerca/suggerimenti?q='.$term)->assertOk()->assertSee('Serata strumentale');
        $this->get('/cerca?q='.$term)->assertOk()->assertSee('Serata strumentale');
    }
    $this->get('/cerca/suggerimenti?q=palcos')->assertSee('Sala del quartiere');
    $this->get('/cerca/suggerimenti?q=improv')->assertSee(route('events.tag', ['tag' => $tag->slug]), false);
});

it('excludes drafts expired events other cities unapproved venues and unapproved tags', function (): void {
    $other = City::factory()->padova()->create(['name' => 'Vicenza', 'slug' => 'vicenza']);
    occurrenceAtLocal($other, $this->category, '2026-09-06 21:00:00', event: ['title' => 'Segreto altrove']);
    $draft = occurrenceAtLocal($this->city, $this->category, '2026-09-06 21:00:00', event: ['title' => 'Segreto bozza']);
    $draft->event->update(['status' => EventStatus::Draft]);
    occurrenceAtLocal($this->city, $this->category, '2026-08-06 21:00:00', event: ['title' => 'Segreto passato']);
    Venue::factory()->create(['city_id' => $this->city->id, 'name' => 'Segreto locale', 'status' => VenueStatus::Pending]);
    Tag::factory()->create(['name' => 'Segreto tag', 'is_approved' => false]);
    $this->get('/cerca/suggerimenti?q=segreto')->assertOk()
        ->assertDontSee('Segreto altrove')->assertDontSee('Segreto bozza')
        ->assertDontSee('Segreto passato')->assertDontSee('Segreto locale')->assertDontSee('Segreto tag');
});

it('validates malformed and oversized input', function (): void {
    $this->getJson('/cerca/suggerimenti?q[]=abc')->assertUnprocessable();
    $this->getJson('/cerca/suggerimenti?q='.str_repeat('a', 121))->assertUnprocessable();
});

it('limits suggestions to five distinct events despite many dates for one event', function (): void {
    $first = occurrenceAtLocal($this->city, $this->category, '2026-09-06 21:00:00', event: ['title' => 'Concerto ricorrente']);
    for ($day = 7; $day <= 13; $day++) {
        EventOccurrence::factory()->create([
            'event_id' => $first->event_id,
            'starts_at' => Carbon::parse('2026-09-'.$day.' 21:00:00', $this->city->timezone)->utc(),
        ]);
    }
    for ($number = 1; $number <= 6; $number++) {
        occurrenceAtLocal($this->city, $this->category, '2026-09-08 21:00:00', event: ['title' => 'Concerto numero '.$number]);
    }
    $this->get('/cerca/suggerimenti?q=concerto')->assertOk()
        ->assertViewHas('events', fn ($events) => $events->count() === 5 && $events->unique('event_id')->count() === 5);
});

it('provides live search hooks and a measured ticker on the home page', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00');
    $this->get('/')->assertOk()->assertSee('data-live-search', false)
        ->assertSee('data-ticker-track', false)->assertSee('data-ticker', false);
});
