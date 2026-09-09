<?php

use App\Models\User;
use App\Models\Venue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 17:00');
    $this->category = testCategory();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'municipality' => 'Padova', 'zone' => 'Guizza']);
});

it('ends the wizard in a shareable search preserving free budget and categories', function () {
    $this->get('/stasera?question=results&municipality=Padova&zone=Guizza&when=tonight&budget=0&categories[]='.$this->category->id)
        ->assertRedirect(route('events.index', ['date' => 'tonight', 'municipality' => 'Padova', 'zone' => 'Guizza', 'budget' => 0, 'category' => $this->category->slug, 'discovery' => 1]));
});

it('returns every matching result instead of five suggestions and excludes nonmatching dates', function () {
    $ids = [];
    for ($i = 0; $i < 7; $i++) {
        $ids[] = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, event: ['price_type' => 'free'])->id;
    }
    foreach (['unknown', 'ticket'] as $price) {
        occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, event: ['price_type' => $price, 'price_min' => 80]);
    }
    occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00', venue: $this->venue, event: ['price_type' => 'free']);
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, event: ['price_type' => 'free'], occurrence: ['status' => 'cancelled']);
    $query = 'municipality=Padova&zone=Guizza&budget=0&discovery=1';
    $this->get('/eventi?date=tonight&'.$query)->assertOk()->assertViewHas('occurrences', fn ($dates) => $dates->total() === 7);
    $response = $this->getJson('/api/v1/events?preset=tonight&'.$query)->assertOk()->assertJsonCount(7, 'data');
    expect(collect($response->json('data'))->pluck('occurrence_id')->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());
    $this->getJson('/api/v1/events?preset=tonight&municipality=Abano%20Terme&discovery=1')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/events?preset=starting_soon&'.$query)->assertOk()->assertJsonCount(0, 'data');
});

it('keeps explicit exclusions in filtered API search', function () {
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue);
    Sanctum::actingAs(User::factory()->create(['content_preferences' => ['mode' => 'all', 'hidden_categories' => [$this->category->id]]]));
    $this->getJson('/api/v1/events?preset=tonight&discovery=1&categories='.$this->category->slug)->assertOk()->assertJsonCount(0, 'data');
});

it('does not recommend an event that already ended earlier tonight', function () {
    $ended = occurrenceAtLocal($this->city, $this->category, '2026-09-10 17:00', venue: $this->venue);
    $next = occurrenceAtLocal($this->city, $this->category, '2026-09-10 23:00', venue: $this->venue);
    freezeLocal($this->city, '2026-09-10 22:00');
    $response = $this->getJson('/api/v1/events?preset=tonight&discovery=1')->assertOk();
    expect(collect($response->json('data'))->pluck('occurrence_id')->all())->toContain($next->id)->not->toContain($ended->id);
});
