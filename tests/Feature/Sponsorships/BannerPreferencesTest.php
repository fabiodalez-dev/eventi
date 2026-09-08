<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Follow;
use App\Models\SavedEvent;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Sponsorship\BannerAffinity;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 20:01');
    $this->first = occurrenceAtLocal($this->city, testCategory(), '2026-09-11 21:00');
    $this->second = occurrenceAtLocal($this->city, Category::factory()->create(), '2026-09-11 21:00');
    $this->a = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $this->first->event_id, 'priority' => 0, 'weight' => 1]);
    $this->b = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $this->second->event_id, 'priority' => 0, 'weight' => 1]);
    $this->user = User::factory()->create();
    Follow::create(['user_id' => $this->user->id, 'followable_type' => 'venue', 'followable_id' => $this->first->event->venue_id]);
});

it('riconosce la sessione web senza condividere il banner personalizzato fra utenti', function (): void {
    $this->actingAs($this->user)->getJson('/padova/banner-sponsorizzato?platform=web')->assertOk()->assertJsonPath('data.id', $this->a->id);
    $other = User::factory()->create();
    $response = $this->actingAs($other)->getJson('/padova/banner-sponsorizzato?platform=web')->assertOk()->assertJsonPath('data.id', $this->b->id);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($this->a->fresh()->weight)->toBe(1);
});

it('riconosce il token Android e non lascia che le preferenze superino la priorità commerciale', function (): void {
    Sanctum::actingAs($this->user);
    $this->getJson('/api/v1/sponsorships/banner?platform=android')->assertOk()->assertJsonPath('data.id', $this->a->id);
    $this->b->update(['priority' => 10]);
    $this->getJson('/api/v1/sponsorships/banner?platform=android')->assertOk()->assertJsonPath('data.id', $this->b->id);
});

it('usa categorie salvate e contesto esplicito senza inventare preferenze anonime', function (): void {
    $this->user->follows()->delete();
    SavedEvent::create(['user_id' => $this->user->id, 'occurrence_id' => $this->first->id]);
    $campaigns = collect([$this->a->load(['event.venue', 'event.category', 'event.tags']), $this->b->load(['event.venue', 'event.category', 'event.tags'])]);
    $affinity = app(BannerAffinity::class);
    expect($affinity->apply($campaigns, $this->city, null, [])->pluck('weight')->all())->toBe([1, 1]);
    expect($affinity->apply($campaigns, $this->city, $this->user, [])->pluck('weight')->all())->toBe([3, 2]);
    expect($affinity->apply($campaigns, $this->city, null, ['category' => $this->second->event->category->slug])->pluck('weight')->all())->toBe([1, 2]);
    expect($campaigns->pluck('weight')->all())->toBe([1, 1]);
});
