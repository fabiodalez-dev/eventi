<?php

use App\Enums\SponsorshipPlacement;
use App\Models\Category;
use App\Models\SavedEvent;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Sponsorship\BannerAffinity;
use App\Services\Sponsorship\SponsorshipSelector;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 12:00');
    $this->music = testCategory();
    $this->other = Category::factory()->create();
    $this->a = occurrenceAtLocal($this->city, $this->music, '2026-09-11 21:00');
    $this->b = occurrenceAtLocal($this->city, $this->other, '2026-09-11 21:00');
    $this->user = User::factory()->create();
});

function contentSelection(array $overrides = []): array
{
    return ['mode' => 'all', 'categories' => [], 'hidden_categories' => [], 'inferred_ads' => true, ...$overrides];
}

it('requires authentication and validates dynamic categories and conflicting choices', function (): void {
    $this->getJson('/api/v1/me/content-preferences')->assertUnauthorized();
    Sanctum::actingAs($this->user);
    $this->patchJson('/api/v1/me/content-preferences', contentSelection(['categories' => [999999]]))->assertUnprocessable();
    $this->patchJson('/api/v1/me/content-preferences', contentSelection(['categories' => [$this->music->id], 'hidden_categories' => [$this->music->id]]))->assertUnprocessable();
    $new = Category::factory()->create();
    $this->getJson('/api/v1/me/content-preferences')->assertOk()->assertJsonFragment(['id' => $new->id, 'name' => $new->name]);
});

it('filters API discovery and map while preserving direct event access and other accounts', function (): void {
    Sanctum::actingAs($this->user);
    $this->patchJson('/api/v1/me/content-preferences', contentSelection(['hidden_categories' => [$this->music->id]]))->assertOk();
    $this->getJson('/api/v1/events?limit=50')->assertOk()
        ->assertJsonMissing(['event_id' => $this->a->event_id])
        ->assertJsonFragment(['event_id' => $this->b->event_id]);
    $this->getJson('/api/v1/map/occurrences?date=2026-09-11')->assertOk()->assertJsonMissing(['event_id' => $this->a->event_id]);
    $this->getJson('/api/v1/home')->assertOk()->assertJsonMissing(['event_id' => $this->a->event_id]);
    $this->getJson('/api/v1/events/'.$this->a->event->slug)->assertOk();
    $this->getJson('/api/v1/occurrences/'.$this->a->id)->assertOk();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/events?limit=50')->assertOk()->assertJsonFragment(['event_id' => $this->a->event_id]);
});

it('filters live search, calendar and feed without silently subscribing to notifications', function (): void {
    $this->a->event->update(['title' => 'Preferenza esclusa speciale']);
    $this->b->event->update(['title' => 'Preferenza inclusa speciale']);
    $this->user->update(['content_preferences' => contentSelection(['categories' => [$this->other->id], 'hidden_categories' => [$this->music->id]])]);
    $this->actingAs($this->user);
    $this->get('/cerca/suggerimenti?q=Preferenza')->assertOk()->assertDontSee('Preferenza esclusa speciale')->assertSee('Preferenza inclusa speciale');
    $this->get('/calendario/2026-09')->assertOk()->assertDontSee('Preferenza esclusa speciale');
    $this->get('/il-mio-feed')->assertOk()->assertSee('Preferenza inclusa speciale')->assertDontSee('Preferenza esclusa speciale');
    expect($this->user->follows()->count())->toBe(0);
});

it('keeps discovery responses private and invalidates results immediately after a preference change', function (): void {
    Sanctum::actingAs($this->user);
    $before = $this->getJson('/api/v1/events')->assertOk();
    expect($before->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->patchJson('/api/v1/me/content-preferences', contentSelection(['mode' => 'selected']))->assertOk();
    $this->withHeader('If-None-Match', $before->headers->get('ETag'))->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');
});

it('lets web users change their mind and does not hide purchases or edit credentials', function (): void {
    $password = $this->user->password;
    $this->actingAs($this->user)->get('/profilo/interessi')->assertOk()->assertSee('I miei interessi');
    $this->patch('/profilo/interessi', ['mode' => 'selected', 'choices' => [$this->music->id => 'interested', $this->other->id => 'hidden'], 'inferred_ads' => '1'])->assertRedirect();
    $this->get('/eventi')->assertOk()
        ->assertSee('href="'.route('events.show', $this->a->event->slug).'"', false)
        ->assertDontSee('href="'.route('events.show', $this->b->event->slug).'"', false);
    $this->patch('/profilo/interessi', ['mode' => 'all', 'choices' => [], 'inferred_ads' => '1'])->assertRedirect();
    $this->get('/eventi')->assertOk()->assertSee('href="'.route('events.show', $this->b->event->slug).'"', false);
    expect($this->user->fresh()->password)->toBe($password);
});

it('supports empty selected mode and keeps new categories hidden until selected', function (): void {
    Sanctum::actingAs($this->user);
    $this->patchJson('/api/v1/me/content-preferences', contentSelection(['mode' => 'selected']))->assertOk();
    $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');
    $new = Category::factory()->create();
    occurrenceAtLocal($this->city, $new, '2026-09-11 21:00');
    $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');
});

it('never advertises excluded categories and ranks explicit preferences above inferred interests', function (): void {
    $a = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $this->a->event_id, 'priority' => 100, 'weight' => 1, 'placement' => SponsorshipPlacement::HomeHero]);
    $b = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $this->b->event_id, 'weight' => 1]);
    SavedEvent::create(['user_id' => $this->user->id, 'occurrence_id' => $this->a->id]);
    $campaigns = collect([$a, $b])->each->load(['event.tags', 'event.category', 'event.venue']);
    $affinity = app(BannerAffinity::class);
    $this->user->update(['content_preferences' => contentSelection(['categories' => [$this->other->id]])]);
    expect($affinity->apply($campaigns, $this->city, $this->user, [])->pluck('weight')->all())->toBe([3, 6]);
    $this->user->update(['content_preferences' => contentSelection(['inferred_ads' => false])]);
    expect($affinity->apply($campaigns, $this->city, $this->user, [])->pluck('weight')->all())->toBe([1, 1]);
    $this->user->update(['content_preferences' => contentSelection(['hidden_categories' => [$this->music->id]])]);
    expect($affinity->apply($campaigns, $this->city, $this->user, [])->pluck('id')->all())->toBe([$b->id]);
    expect(app(SponsorshipSelector::class)->first($this->city, SponsorshipPlacement::HomeHero, user: $this->user))->toBeNull();
});
