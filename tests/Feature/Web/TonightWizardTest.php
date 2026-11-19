<?php

use App\Models\User;
use App\Models\Venue;
use App\Services\Search\TonightDiscovery;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 17:00');
    $this->category = testCategory();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'municipality' => 'Padova', 'zone' => 'Centro']);
});

it('shows one question per screen with step indicator, back, skip and a single primary action', function (string $question, int $current, int $total): void {
    $html = $this->get('/stasera?question='.$question.'&municipality=Padova')->assertOk()
        ->assertSee('data-wizard-actions', false)
        ->assertSee(__('tonight.progress', ['current' => $current, 'total' => $total]))
        ->assertSee('aria-current="step"', false)
        ->assertSee(__('tonight.back'))
        ->assertSee(__('tonight.skip'))
        ->assertSee('data-tonight-summary', false)
        ->assertSee('data-tonight-preview', false)
        ->assertDontSee('appuntamenti')
        ->getContent();
    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('>'.e(__('tonight.'.$question)).'</h1>')
        ->and(substr_count($html, 'tonight-actions__next'))->toBe(1)
        // I comandi vengono subito dopo la domanda, prima di anteprima e riepilogo:
        // da desktop si vedono senza scorrere, e Invio porta avanti.
        ->and(strpos($html, 'data-wizard-actions'))->toBeLessThan(strpos($html, 'data-tonight-preview'))
        ->and(strpos($html, 'data-wizard-actions'))->toBeGreaterThan(strpos($html, '</fieldset>'));
})->with([
    ['when', 1, 5], ['municipality', 2, 5], ['district', 3, 5], ['budget', 4, 5], ['categories', 5, 5],
]);

it('counts four steps outside Padova, where there is no neighborhood question', function (): void {
    $this->get('/stasera?question=budget&municipality=Abano%20Terme')->assertOk()
        ->assertSee(__('tonight.progress', ['current' => 3, 'total' => 4]))
        ->assertDontSee(__('tonight.summary.district'));
});

it('labels the last action with the number of matching events, never with ambiguous dates', function (): void {
    $this->get('/stasera?question=categories&municipality=Padova')->assertOk()
        ->assertSee(trans_choice('tonight.see_events', 0));
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue);
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 22:00', venue: $this->venue);
    $this->get('/stasera?question=categories&municipality=Padova')->assertOk()
        ->assertSee(trans_choice('tonight.see_events', 2, ['count' => 2]))
        ->assertSee(trans_choice('tonight.preview.all', 2, ['count' => 2]));
});

it('builds back and skip links that keep the other answers and reset only this one', function (): void {
    $html = $this->get('/stasera?question=budget&when=starting_soon&municipality=Padova&zone=Centro&budget=20')->assertOk()->getContent();
    // The answers keep the order of the validated input.
    $back = route('tonight.wizard', ['municipality' => 'Padova', 'when' => 'starting_soon', 'zone' => 'Centro', 'budget' => '20', 'question' => 'district']);
    $skip = route('tonight.wizard', ['municipality' => 'Padova', 'when' => 'starting_soon', 'zone' => 'Centro', 'budget' => '', 'question' => 'categories']);
    expect($html)->toContain('href="'.e($back).'"')->toContain('href="'.e($skip).'"');
    // The first question goes back home; skipping the municipality means everywhere.
    $this->get('/stasera')->assertOk()->assertSee('href="'.e(route('home')).'"', false);
    $this->get('/stasera?question=municipality&municipality=Padova')->assertOk()
        ->assertSee('href="'.e(route('tonight.wizard', ['municipality' => '', 'question' => 'district'])).'"', false);
    $this->get('/stasera?municipality=&question=district')->assertOk()->assertViewHas('question', 'budget');
});

it('summarizes the choices made so far with links to change them', function (): void {
    $html = $this->get('/stasera?question=budget&when=starting_soon&municipality=Padova&zone=Centro')->assertOk()->getContent();
    expect($html)->toContain(__('tonight.summary.title'))
        ->toContain(__('tonight.soon'))->toContain('Centro')
        ->toContain(__('tonight.summary.change'))->toContain(__('tonight.summary.pending'))
        ->toContain('href="'.e(route('tonight.wizard', ['municipality' => 'Padova', 'when' => 'starting_soon', 'zone' => 'Centro', 'question' => 'when'])).'"');
});

it('previews the first matching dates with posters and refreshes them while choosing', function (): void {
    $match = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, event: ['price_type' => 'free', 'title' => 'Concerto gratuito in centro']);
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, event: ['price_type' => 'ticket', 'price_min' => 40, 'title' => 'Concerto caro in centro']);
    $this->get('/stasera?question=budget&municipality=Padova')->assertOk()
        ->assertSee('data-event-row', false)->assertSee('Concerto gratuito in centro')->assertSee('Concerto caro in centro');

    $response = $this->getJson('/stasera?question=budget&municipality=Padova&budget=0&preview=1')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.total', 1);
    expect($response->json('html'))->toContain('Concerto gratuito in centro')->not->toContain('Concerto caro in centro')
        ->toContain('data-tonight-summary')->toContain(__('tonight.free'))
        ->not->toContain('<html');

    $this->getJson('/api/v1/tonight?budget=0&preview=1')->assertOk()->assertJsonPath('data.total', 1)->assertJsonMissingPath('html');
    expect($match->id)->toBeInt();
});

it('shows an honest empty preview instead of unrelated events', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00', venue: $this->venue);
    $this->get('/stasera?question=when&municipality=Padova')->assertOk()
        ->assertSee(__('tonight.preview.empty'))
        ->assertDontSee('data-event-row', false);
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
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', venue: $this->venue, event: ['content_details' => ['membership' => 'required', 'membership_notes' => 'Tessera annuale']]);
    $facts = collect(app(TonightDiscovery::class)->practical($date))->pluck('value', 'label');
    expect($facts[__('seo.fields.membership_notes')])->toContain('Tessera annuale');
    expect($facts[__('seo.fields.parking_notes')])->toBe(__('seo.parking_none'));
    expect($facts[__('seo.fields.mandatory_costs')])->toBe(__('tonight.unknown'));
});
