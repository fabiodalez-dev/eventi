<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\SponsorshipPlacement;
use App\Models\Sponsorship;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-05 15:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('keeps the four counters in two columns until the wide layout', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-05 18:00:00');

    $this->get('/')->assertOk()
        ->assertDontSee(__('seo.fields.accessibility').':')
        ->assertSee('grid-cols-2')
        ->assertSee('min-[840px]:grid-cols-4')
        ->assertDontSee('repeat(auto-fit,minmax(min(200px,100%),1fr))');
});

it('reserves a portrait placeholder for a today event without a poster', function (): void {
    $today = occurrenceAtLocal($this->city, $this->category, '2026-09-05 18:00:00');
    occurrenceAtLocal($this->city, $this->category, '2026-09-06 18:00:00');

    $response = $this->get('/')->assertOk()
        ->assertViewHas('hero', fn ($hero) => $hero->is($today))
        ->assertViewHas('heroSponsorship', null)
        ->assertSee(__('events.card.poster_missing'))
        ->assertSee('data-home-hero', false);

    $response->assertDontSee('home-event-fallback.jpg')
        ->assertSee('event-poster-frame');
});

it('keeps a real poster instead of the fallback', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-05 18:00:00', event: ['poster' => '/real-poster.jpg']);
    $this->get('/')->assertOk()->assertSee('/real-poster.jpg')->assertDontSee('home-event-fallback.jpg');
});

it('prioritizes the active hero sponsorship and uses its actual occurrence date', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-05 18:00:00');
    $sponsored = occurrenceAtLocal($this->city, $this->category, '2026-09-08 21:45:00');
    $campaign = Sponsorship::factory()->create([
        'city_id' => $this->city->id, 'event_id' => $sponsored->event_id,
        'placement' => SponsorshipPlacement::HomeHero,
    ]);
    $this->get('/')->assertOk()
        ->assertViewHas('hero', fn ($hero) => $hero->is($sponsored))
        ->assertViewHas('heroSponsorship', fn ($selected) => $selected->is($campaign))
        ->assertSee('rel="sponsored"', false)->assertSee('21:45')
        ->assertSee(__('events.card.poster_missing'));
});

it('does not drop a sponsored event that has already started but is still ongoing', function (): void {
    $ongoing = occurrenceAtLocal($this->city, $this->category, '2026-09-05 14:00:00', '2026-09-05 17:00:00');
    Sponsorship::factory()->create([
        'city_id' => $this->city->id, 'event_id' => $ongoing->event_id,
        'placement' => SponsorshipPlacement::HomeHero,
    ]);
    $this->get('/')->assertOk()->assertViewHas('hero', fn ($hero) => $hero->is($ongoing))
        ->assertSee('rel="sponsored"', false);
});

it('falls back to today when a sponsorship has expired', function (): void {
    $today = occurrenceAtLocal($this->city, $this->category, '2026-09-05 18:00:00');
    $future = occurrenceAtLocal($this->city, $this->category, '2026-09-08 18:00:00');
    Sponsorship::factory()->expired()->create([
        'city_id' => $this->city->id, 'event_id' => $future->event_id,
        'placement' => SponsorshipPlacement::HomeHero,
    ]);
    $this->get('/')->assertOk()->assertViewHas('hero', fn ($hero) => $hero->is($today))
        ->assertViewHas('heroSponsorship', null);
});

it('uses the next available event after todays events have ended', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-05 18:00:00', occurrence: ['status' => OccurrenceStatus::Cancelled]);
    occurrenceAtLocal($this->city, $this->category, '2026-09-05 10:00:00', '2026-09-05 11:00:00');
    $next = occurrenceAtLocal($this->city, $this->category, '2026-09-06 18:00:00');
    $this->get('/')->assertOk()->assertViewHas('hero', fn ($hero) => $hero->is($next))->assertSee('data-home-hero', false);
});

it('skips an invalid high priority campaign before choosing another active sponsorship', function (): void {
    $cancelled = occurrenceAtLocal($this->city, $this->category, '2026-09-05 18:00:00', occurrence: ['status' => OccurrenceStatus::Cancelled]);
    $valid = occurrenceAtLocal($this->city, $this->category, '2026-09-08 18:00:00');
    foreach ([[$cancelled, 100], [$valid, 0]] as [$occurrence, $priority]) {
        Sponsorship::factory()->create([
            'city_id' => $this->city->id, 'event_id' => $occurrence->event_id,
            'placement' => SponsorshipPlacement::HomeHero, 'priority' => $priority,
        ]);
    }
    $this->get('/')->assertOk()->assertViewHas('hero', fn ($hero) => $hero->is($valid));
});
