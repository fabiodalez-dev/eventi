<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Venue;

afterEach(fn () => Carbon\Carbon::setTestNow());

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    freezeLocal($this->city, '2026-10-23 12:00');
});

it('hides a venue from listings and the API but keeps it reachable and manageable', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Locale Rumoroso']);
    $hiddenDate = occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', event: ['title' => 'Serata Rumorosa'], venue: $venue);
    occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', event: ['title' => 'Serata Tranquilla']);
    $this->actingAs($this->user)->get(route('venues.show', $venue))->assertOk()->assertSee(__('account.hidden_venues.hide'));
    $this->actingAs($this->user)->post(route('account.hidden-venues.store', $venue->id))->assertRedirect();
    $html = $this->actingAs($this->user)->get(route('events.index'))->assertOk()->getContent();
    expect($html)->toContain('Serata Tranquilla')->not->toContain('Serata Rumorosa');
    $this->actingAs($this->user)->get(route('events.show', $hiddenDate->event))->assertOk()->assertSee('Serata Rumorosa');
    $this->app['auth']->forgetGuards();
    $this->get(route('events.index'))->assertOk()->assertSee('Serata Rumorosa');
    $prefs = $this->actingAs($this->user)->getJson('/api/v1/me/content-preferences')->assertOk();
    expect($prefs->json('data.selection.hidden_venues'))->toBe([$venue->id])
        ->and($prefs->json('data.hidden_venue_options.0.name'))->toBe('Locale Rumoroso');
    // An older client patching without the new keys keeps them.
    $this->actingAs($this->user)->patchJson('/api/v1/me/content-preferences', ['mode' => 'all', 'categories' => [], 'hidden_categories' => [], 'inferred_ads' => true])->assertOk();
    expect($this->user->fresh()->content_preferences['hidden_venues'])->toBe([$venue->id]);
    $this->actingAs($this->user)->get(route('account.content-preferences'))->assertOk()->assertSee('Locale Rumoroso');
    $this->actingAs($this->user)->deleteJson('/api/v1/me/hidden-venues/'.$venue->id)->assertNoContent();
    $this->actingAs($this->user)->get(route('events.index'))->assertOk()->assertSee('Serata Rumorosa');
});

it('applies profile accessibility needs to the listings unless the address chooses otherwise', function (): void {
    $access = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'accessibility' => ['step_free_entrance' => true]]);
    $stairs = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'accessibility' => ['step_free_entrance' => false]]);
    occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', event: ['title' => 'Senza gradini'], venue: $access);
    occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', event: ['title' => 'Con le scale'], venue: $stairs);
    $this->actingAs($this->user)->patch(route('account.content-preferences.update'), ['mode' => 'all', 'choices' => [], 'access_needs_present' => 1, 'access_needs' => ['step_free_entrance']])->assertRedirect();
    expect($this->user->fresh()->content_preferences['access_needs'])->toBe(['step_free_entrance']);
    $this->actingAs($this->user)->get(route('events.index'))->assertOk()->assertSee('Senza gradini')->assertDontSee('Con le scale')->assertSee(__('account.access_needs.show_all'));
    $this->actingAs($this->user)->get(route('events.index', ['access' => 'nessuna']))->assertOk()->assertSee('Con le scale')->assertDontSee('data-profile-needs', false);
    expect($this->actingAs($this->user)->getJson('/api/v1/me/content-preferences')->json('data.selection.access_needs'))->toBe(['step_free_entrance']);
    $titles = collect($this->getJson('/api/v1/events?access=step_free_entrance,inesistente')->assertOk()->json('data'))->pluck('title')->all();
    expect($titles)->toContain('Senza gradini')->not->toContain('Con le scale');
    $this->actingAs($this->user)->patch(route('account.content-preferences.update'), ['mode' => 'all', 'choices' => [], 'access_needs_present' => 1])->assertRedirect();
    expect($this->user->fresh()->content_preferences['access_needs'])->toBe([]);
});
