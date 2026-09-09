<?php

use App\Models\User;
use App\Models\Venue;
use App\Services\Search\TonightDiscovery;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 17:00');
    $this->category = testCategory();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'zone' => 'Centro']);
});

it('renders all wizard steps and returns the same actual dates on web and API', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, event: ['price_type' => 'free']);
    $this->get('/stasera')->assertOk()->assertSee('La tua prossima serata.');
    $this->get('/stasera?step=2&zone=Centro&budget=0')->assertOk()->assertSee($this->category->name);
    $this->followingRedirects()->get('/stasera?step=3&zone=Centro&budget=0')->assertOk()->assertSee($date->event->title);
    $this->getJson('/api/v1/tonight?step=3&zone=Centro&budget=0')->assertOk()
        ->assertJsonPath('data.results.0.occurrence.occurrence_id', $date->id)
        ->assertJsonCount(3, 'data.results.0.reasons');
    $this->getJson('/api/v1/tonight')->assertOk()->assertJsonCount(0, 'data.results');
});

it('never fills a shortlist with unknown prices, unavailable dates, other zones or tomorrow', function (): void {
    foreach (['cancelled', 'sold_out', 'postponed'] as $status) {
        occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, occurrence: ['status' => $status], event: ['price_type' => 'free']);
    }
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, event: ['price_type' => 'unknown', 'price_min' => 5]);
    occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00', venue: $this->venue, event: ['price_type' => 'free']);
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: Venue::factory()->approved()->create(['city_id' => $this->city->id, 'zone' => 'Arcella']), event: ['price_type' => 'free']);
    $this->getJson('/api/v1/tonight?step=3&zone=Centro&budget=10')->assertOk()->assertJsonCount(0, 'data.results');
    $this->get('/stasera?step=3&zone=Centro&budget=10')->assertRedirect(route('events.index', ['date' => 'tonight', 'zone' => 'Centro', 'budget' => 10, 'discovery' => 1]));
});

it('keeps explicit exclusions even when a category is requested and isolates personalized responses', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, event: ['price_type' => 'free']);
    Sanctum::actingAs(User::factory()->create(['content_preferences' => ['mode' => 'all', 'hidden_categories' => [$this->category->id]]]));
    $this->getJson('/api/v1/tonight?step=3&categories[]='.$this->category->id)->assertOk()->assertJsonCount(0, 'data.results')->assertJsonCount(0, 'data.categories');
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/tonight?step=3')->assertOk()->assertJsonCount(1, 'data.results');
});

it('limits recommendations to five unique events and validates inputs', function (): void {
    for ($i = 0; $i < 7; $i++) {
        occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, event: ['price_type' => 'free']);
    }
    $results = app(TonightDiscovery::class)->find($this->city, []);
    expect($results)->toHaveCount(5);
    expect($results->pluck('event_id')->unique())->toHaveCount(5);
    $this->getJson('/api/v1/tonight?step=4')->assertUnprocessable();
    $this->getJson('/api/v1/tonight?budget=-1')->assertUnprocessable();
    $this->getJson('/api/v1/tonight?categories[]=999999')->assertUnprocessable();
});

it('uses the effective date price rather than a cheaper event default', function (): void {
    $expensive = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue,
        event: ['price_type' => 'free'], occurrence: ['price_override' => ['price_type' => 'ticket', 'price_min' => 80]]);
    $free = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue,
        event: ['price_type' => 'ticket', 'price_min' => 80], occurrence: ['price_override' => ['price_type' => 'free']]);
    $results = app(TonightDiscovery::class)->find($this->city, ['budget' => '20']);
    expect($results->modelKeys())->toContain($free->id)->not->toContain($expensive->id);
    expect(app(TonightDiscovery::class)->find($this->city, ['budget' => '0'])->modelKeys())->toBe([$free->id]);
});

it('shows unknown facts without hiding declared parking or required membership', function (): void {
    $this->venue->update(['requires_membership' => true, 'membership_notes' => 'Tessera annuale', 'content_details' => ['parking_type' => 'none']]);
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, event: ['content_details' => []]);
    $facts = collect(app(TonightDiscovery::class)->practical($date))->pluck('value', 'label');
    expect($facts[__('seo.fields.membership_notes')])->toContain('Tessera annuale');
    expect($facts[__('seo.fields.parking_notes')])->toBe(__('seo.parking_none'));
    expect($facts[__('seo.fields.mandatory_costs')])->toBe(__('tonight.unknown'));
});
