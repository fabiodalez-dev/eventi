<?php

use App\DTOs\EventFilters;
use App\Models\User;
use App\Models\Venue;
use App\Services\Search\ContextualFacets;
use App\Services\Search\TonightDiscovery;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 17:00');
    $this->dj = testCategory(['name' => 'DJ set', 'slug' => 'dj-set', 'is_nightlife' => true]);
    $this->cinema = testCategory(['name' => 'Cinema', 'slug' => 'cinema']);
    $this->emptyCategory = testCategory(['name' => 'Teatro', 'slug' => 'teatro']);
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'municipality' => 'Padova', 'zone' => 'Guizza', 'accessibility' => ['step_free_entrance' => true]]);
    $this->other = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'municipality' => 'Abano Terme', 'zone' => null, 'accessibility' => null]);
    $this->date = occurrenceAtLocal($this->city, $this->dj, '2026-09-10 23:00', event: ['title' => 'Suoni notturni', 'price_type' => 'free', 'is_outdoor' => true], venue: $this->venue);
    occurrenceAtLocal($this->city, $this->cinema, '2026-09-11 15:00', event: ['title' => 'Cinema pomeridiano', 'price_type' => 'ticket', 'price_min' => 15], venue: $this->other);
});

it('intersects every current constraint across the entire catalog', function (array $input, array $expected) {
    $counts = app(ContextualFacets::class)->build($this->city, EventFilters::fromArray($input));
    expect(array_keys(array_filter($counts['category'])))->toEqualCanonicalizing($expected);
})->with([
    'all upcoming' => [[], ['dj-set', 'cinema']],
    'today' => [['date' => 'today'], ['dj-set']],
    'tonight' => [['date' => 'tonight'], ['dj-set']],
    'tomorrow' => [['date' => 'tomorrow'], ['cinema']],
    'soon empty' => [['date' => 'starting_soon'], []],
    'free' => [['price' => 'free'], ['dj-set']],
    'ten euros' => [['price' => 'max10'], ['dj-set']],
    'twenty euros' => [['price' => 'max20'], ['dj-set', 'cinema']],
    'donation unavailable' => [['price' => 'donation'], []],
    'daytime' => [['time' => 'day'], ['cinema']],
    'evening empty' => [['time' => 'evening'], []],
    'nighttime' => [['time' => 'night'], ['dj-set']],
    'municipality' => [['municipality' => 'Padova'], ['dj-set']],
    'other municipality' => [['municipality' => 'Abano Terme'], ['cinema']],
    'district' => [['zone' => 'Guizza'], ['dj-set']],
    'unknown district' => [['zone' => 'Arcella'], []],
    'category' => [['category' => 'cinema'], ['cinema']],
    'multiple categories OR' => [['category' => 'dj-set,cinema'], ['dj-set', 'cinema']],
    'incompatible combination' => [['date' => 'today', 'category' => 'cinema'], []],
    'accessibility' => [['accessible' => '1'], ['dj-set']],
    'outdoor' => [['outdoor' => '1'], ['dj-set']],
    'several constraints' => [['date' => 'today', 'price' => 'free', 'time' => 'night', 'zone' => 'Guizza'], ['dj-set']],
]);

it('counts categories independently for additive checkbox choices and disables empty options', function () {
    $counts = app(TonightDiscovery::class)->counts($this->city, ['when' => 'tonight', 'categories' => [$this->emptyCategory->id]]);
    expect($counts['categories'][$this->dj->id])->toBe(1)->and($counts['categories'][$this->emptyCategory->id])->toBe(0);
    $html = $this->get('/stasera?question=categories&when=tonight')->assertOk()->getContent();
    expect($html)->toContain('data-option-count="'.$this->dj->id.'"');
    expect($html)->toMatch('/value="'.$this->emptyCategory->id.'"\s+disabled/');
});

it('counts budget alternatives and keeps object maps in the API', function () {
    $response = $this->getJson('/api/v1/tonight?when=tonight&municipality=Padova')->assertOk();
    $response->assertJsonPath('data.counts.budgets.0', 1)->assertJsonPath('data.counts.categories.'.$this->dj->id, 1);
    expect(json_decode($response->getContent())->data->counts->categories)->toBeInstanceOf(stdClass::class);
    $this->get('/stasera?question=budget&when=tonight')->assertOk()->assertSee('data-count-kind="budgets"', false);
});

it('serves the same contextual counts to Android including empty maps', function () {
    $response = $this->getJson('/api/v1/events/facets?preset=today')->assertOk();
    $response->assertJsonPath('data.category.dj-set', 1)->assertJsonPath('data.time.night', 1)->assertJsonPath('data.time.day', 0);
    $empty = $this->getJson('/api/v1/events/facets?preset=today&categories=cinema')->assertOk();
    expect(json_decode($empty->getContent())->data->category)->toBeInstanceOf(stdClass::class);
});

it('provides calendar day modal triggers and close control on desktop', function () {
    $this->get('/calendario')->assertOk()->assertSee('data-calendar-day=', false)->assertSee('id="calendar-day-preview"', false)->assertSee('method="dialog"', false);
});

it('hides unavailable chips but preserves selected zero-result filters for removal', function () {
    $html = $this->get('/eventi?date=today')->assertOk()->getContent();
    $sidebar = explode('</aside>', explode('<aside', $html)[1])[0];
    expect($sidebar)->toContain('DJ set')->not->toContain('Cinema', 'Teatro');
    $this->get('/eventi?date=today&category=cinema')->assertOk()->assertSee('Cinema');
});

it('does not reveal explicitly excluded categories through counts', function () {
    Sanctum::actingAs(User::factory()->create(['content_preferences' => ['mode' => 'all', 'hidden_categories' => [$this->dj->id]]]));
    $this->getJson('/api/v1/events/facets?preset=today')->assertOk()->assertJsonMissingPath('data.category.dj-set');
    $this->getJson('/api/v1/tonight?when=tonight')->assertOk()->assertJsonPath('data.counts.categories.'.$this->dj->id, 0);
});

it('uses occurrence venue overrides in contextual place choices', function () {
    $this->date->update(['venue_id' => $this->other->id]);
    $counts = app(ContextualFacets::class)->build($this->city, EventFilters::fromArray(['date' => 'today']));
    expect($counts['municipality'])->toBe(['Abano Terme' => 1])->and($counts['zone'])->toBe([]);
});

it('uses date-specific prices in wizard counts and excludes cancelled dates', function () {
    $this->date->update(['price_override' => ['price_type' => 'ticket', 'price_min' => 25]]);
    $counts = app(TonightDiscovery::class)->counts($this->city, ['when' => 'tonight']);
    expect($counts['budgets'][0])->toBe(0)->and($counts['budgets'][20])->toBe(0)->and($counts['budgets'][30])->toBe(1);
    $this->date->update(['status' => 'cancelled']);
    expect(app(TonightDiscovery::class)->counts($this->city, ['when' => 'tonight'])['categories'][$this->dj->id])->toBe(0);
});

it('never limits available categories to the first page', function () {
    config(['eventi.per_page' => 1]);
    $this->get('/eventi')->assertOk()->assertViewHas('facetCounts', fn ($counts) => ($counts['category']['cinema'] ?? 0) === 1 && ($counts['category']['dj-set'] ?? 0) === 1);
});
