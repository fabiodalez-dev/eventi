<?php

use App\Filament\Venue\Pages\VenueProfile;
use App\Models\Venue;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 17:00');
});

it('serves 101 unique current municipalities with Padova first', function (): void {
    $names = $this->getJson('/api/v1/tonight')->assertOk()->json('data.municipalities');
    expect($names)->toHaveCount(101)->and(array_unique($names))->toHaveCount(101);
    expect($names[0])->toBe('Padova');
    expect($names)->toContain('Borgo Veneto', "Santa Caterina d'Este")->not->toContain('Carceri', 'Saletto');
});

it('serves human neighborhoods not administrative districts', function (): void {
    $names = $this->getJson('/api/v1/tonight')->assertOk()->json('data.zones');
    expect($names)->toContain('Brusegana', 'Guizza', 'Arcella')->not->toContain('Nord', 'Sud-Est');
});

it('links the wizard from the home hero', function (): void {
    $this->get('/')->assertOk()->assertSee(__('tonight.hero_action'))->assertSee(route('tonight.wizard'), false);
});

it('starts with Padova selected and only the municipality question', function (): void {
    $this->get('/stasera')->assertOk()->assertViewHas('question', 'municipality')
        ->assertViewHas('input', fn ($input) => $input['municipality'] === 'Padova')
        ->assertDontSee('<legend class="mb-6 font-display text-2xl font-bold sm:text-3xl">'.__('tonight.budget'), false);
});

it('advances or skips the neighborhood question appropriately', function (string $municipality, string $question, string $expected): void {
    $this->get('/stasera?'.http_build_query(['municipality' => $municipality, 'question' => $question, 'zone' => 'Brusegana', 'budget' => '0']))
        ->assertOk()->assertViewHas('question', $expected)
        ->assertViewHas('input', fn ($input) => $input['budget'] === '0' && ($municipality === 'Padova' || $input['zone'] === null));
})->with([
    ['Padova', 'district', 'district'], ['Abano Terme', 'district', 'when'], ['', 'district', 'when'],
    ['Padova', 'budget', 'budget'], ['Padova', 'categories', 'categories'],
]);

it('rejects invalid geographical and wizard input', function (array $input): void {
    $this->getJson('/api/v1/tonight?'.http_build_query($input))->assertUnprocessable();
})->with([
    [['municipality' => 'Atlantide']], [['municipality' => 'Padova', 'zone' => 'Nord']],
    [['question' => 'unknown']], [['municipality' => ['Padova']]],
]);

it('filters the actual occurrence venue instead of the parent venue', function (): void {
    $category = testCategory();
    $parent = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'municipality' => 'Padova', 'zone' => 'Brusegana']);
    $actual = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'municipality' => 'Abano Terme', 'zone' => null]);
    $date = occurrenceAtLocal($this->city, $category, '2026-09-10 21:00', venue: $parent, occurrence: ['venue_id' => $actual->id]);
    $this->getJson('/api/v1/tonight?step=3&municipality=Padova&zone=Brusegana')->assertJsonCount(0, 'data.results');
    $this->getJson('/api/v1/tonight?step=3&municipality=Abano%20Terme&zone=Brusegana')->assertOk()->assertJsonPath('data.results.0.occurrence.occurrence_id', $date->id);
});

it('includes multiple municipalities for everywhere despite stale neighborhood', function (): void {
    $category = testCategory();
    foreach (['Padova', 'Abano Terme'] as $municipality) {
        $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'municipality' => $municipality, 'zone' => null]);
        occurrenceAtLocal($this->city, $category, '2026-09-10 21:00', venue: $venue);
    }
    $this->getJson('/api/v1/tonight?step=3&municipality=&zone=Guizza')->assertOk()->assertJsonCount(2, 'data.results');
});

it('keeps unknown neighborhoods visible for all Padova but never guesses a match', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'municipality' => 'Padova', 'zone' => null]);
    occurrenceAtLocal($this->city, testCategory(), '2026-09-10 21:00', venue: $venue);
    $this->getJson('/api/v1/tonight?step=3&municipality=Padova')->assertJsonCount(1, 'data.results');
    $this->getJson('/api/v1/tonight?step=3&municipality=Padova&zone=Guizza')->assertJsonCount(0, 'data.results');
});

it('validates neighborhood choices when a venue saves its profile', function (string $municipality, ?string $zone, bool $valid): void {
    $scenario = VenueIsolationScenario::make();
    // This fixture creates a second Padova with a unique slug (padova-1).
    config(['discovery-geography.'.$scenario->city->slug => config('discovery-geography.padova')]);
    Filament::setCurrentPanel('venue');
    $this->actingAs($scenario->ownerA);
    Filament::setTenant($scenario->venueA);
    $form = Livewire::test(VenueProfile::class)->fillForm(['municipality' => $municipality, 'zone' => $zone])->call('save');
    if ($valid) {
        $form->assertHasNoFormErrors();
        expect($scenario->venueA->fresh()->zone)->toBe($municipality === 'Padova' ? $zone : null);
    } else {
        $form->assertHasFormErrors(['zone']);
    }
})->with([
    ['Padova', 'Brusegana', true], ['Padova', null, false], ['Padova', 'Nord', false], ['Abano Terme', 'Guizza', true],
    ['Padova', 'Centro storico', true],
]);
