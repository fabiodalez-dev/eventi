<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Follow;
use App\Models\SavedEvent;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Sponsorship\BannerAffinity;
use App\Services\Sponsorship\Delivery\Draw;
use App\Services\Sponsorship\Delivery\SeededDraw;
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

/*
 * Il banner estrae a ogni richiesta dal piano (D70): la rotazione ferma nel
 * minuto non c'è più. Queste due prove verificano la stessa promessa di prima
 * su 50 richieste con un caso fisso: chi segue il locale di A vede A quattro
 * volte su cinque (peso 1 × 4 contro 1), chi non segue niente una volta su due.
 */
function bannerPreferenceCount(callable $request, int $id, int $times = 50): int
{
    $count = 0;

    for ($i = 0; $i < $times; $i++) {
        $count += $request()->assertOk()->json('data.id') === $id ? 1 : 0;
    }

    return $count;
}

it('riconosce la sessione web senza condividere il banner personalizzato fra utenti', function (): void {
    app()->instance(Draw::class, new SeededDraw(20261119));
    $other = User::factory()->create();

    /* Atteso 40 su 50 (deviazione 2,8) e 25 su 50 (deviazione 3,5). */
    expect(bannerPreferenceCount(fn () => $this->actingAs($this->user)->getJson('/padova/banner-sponsorizzato?platform=web'), $this->a->id))
        ->toBeGreaterThanOrEqual(32)->toBeLessThanOrEqual(48);
    expect(bannerPreferenceCount(fn () => $this->actingAs($other)->getJson('/padova/banner-sponsorizzato?platform=web'), $this->a->id))
        ->toBeGreaterThanOrEqual(15)->toBeLessThanOrEqual(35);

    $response = $this->actingAs($other)->getJson('/padova/banner-sponsorizzato?platform=web')->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($this->a->fresh()->weight)->toBe(1);
});

it('riconosce il token Android e non lascia che le preferenze superino la priorità commerciale', function (): void {
    app()->instance(Draw::class, new SeededDraw(20261119));
    Sanctum::actingAs($this->user);

    expect(bannerPreferenceCount(fn () => $this->getJson('/api/v1/sponsorships/banner?platform=android'), $this->a->id))
        ->toBeGreaterThanOrEqual(32)->toBeLessThanOrEqual(48);

    $this->b->update(['priority' => 10]);
    expect(bannerPreferenceCount(fn () => $this->getJson('/api/v1/sponsorships/banner?platform=android'), $this->b->id, 20))->toBe(20);
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
