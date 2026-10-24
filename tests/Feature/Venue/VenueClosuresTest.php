<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Venue\Pages\VenueProfile;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Models\User;
use App\Models\Venue;
use App\Support\OpeningHours;
use App\Support\VenueClosures;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

afterEach(function (): void {
    Carbon\Carbon::setTestNow();
    Filament\Facades\Filament::setTenant(null);
    Filament\Facades\Filament::setCurrentPanel(null);
});

beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-10-24 12:00');
    $this->hours = ['sat' => [['open' => '10:00', 'close' => '23:00']], 'sun' => [['open' => '10:00', 'close' => '23:00']]];
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria del Ponte', 'opening_hours' => $this->hours,
        'closures' => [['from' => '2026-10-24', 'to' => '2026-10-26', 'note' => 'Lavori in cucina'], ['from' => '2026-12-24', 'to' => '2027-01-06', 'note' => null]]]);
});

it('keeps only valid, unfinished closures in order and rejects an end before the start', function (): void {
    $rows = [['from' => '2026-12-24', 'to' => '2026-12-26', 'note' => ' Natale '], ['from' => '2026-10-01', 'to' => '2026-10-05'], ['from' => '2026-11-02', 'to' => '2026-11-02', 'note' => '']];
    expect(VenueClosures::normalize($rows, 'Europe/Rome'))->toBe([
        ['from' => '2026-11-02', 'to' => '2026-11-02', 'note' => null],
        ['from' => '2026-12-24', 'to' => '2026-12-26', 'note' => 'Natale'],
    ])->and(VenueClosures::normalize([], 'Europe/Rome'))->toBeNull()
        ->and(fn () => VenueClosures::normalize([['from' => '2026-11-05', 'to' => '2026-11-04']], 'Europe/Rome'))->toThrow(ValidationException::class);
});

it('closes the venue during a closure even when the hours say open, on the API and in the open-now filter', function (): void {
    expect(OpeningHours::state($this->hours, now(), 'Europe/Rome', $this->venue->closures))->toBe(['open' => false, 'until' => null])
        ->and(OpeningHours::state($this->hours, now(), 'Europe/Rome'))->toBe(['open' => true, 'until' => '23:00']);
    $this->getJson('/api/v1/venues/'.$this->venue->slug)->assertOk()
        ->assertJsonPath('data.open_now', false)
        ->assertJsonPath('data.closure_now', ['from' => '2026-10-24', 'to' => '2026-10-26', 'note' => 'Lavori in cucina'])
        ->assertJsonCount(2, 'data.closures');
    expect(collect($this->getJson('/api/v1/venues?open_now=1')->json('data'))->pluck('slug'))->not->toContain($this->venue->slug);

    $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', 'Europe/Rome'));
    $this->getJson('/api/v1/venues/'.$this->venue->slug)->assertJsonPath('data.closure_now', null)->assertJsonCount(1, 'data.closures');
});

it('shows the closures on the venue page and passes them to the open-now badge', function (): void {
    $this->get(route('venues.show', $this->venue))->assertOk()
        ->assertSee(__('venues.detail.closures'))->assertSee('Lavori in cucina')->assertSee('data-closures=', false)->assertSee('data-closure-ends="2027-01-06"', false);
});

it('saves closures from the venue panel and warns when a new date falls inside one', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $owner->venues()->attach($this->venue, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => now()]);
    $this->actingAs($owner)->get('/gestione/'.$this->venue->slug.'/eventi/nuovo')->assertOk();

    Livewire::test(CreateEvent::class)
        ->fillForm(['title' => 'Cena', 'starts_at' => CarbonImmutable::parse('2026-12-31 20:00', 'Europe/Rome')->utc()->format('Y-m-d H:i:s')])
        ->assertSee(__('manage.closures.warning'))->assertSee('24/12/2026')
        ->fillForm(['starts_at' => CarbonImmutable::parse('2027-01-10 20:00', 'Europe/Rome')->utc()->format('Y-m-d H:i:s')])
        ->assertDontSee(__('manage.closures.warning'));

    Livewire::test(VenueProfile::class)
        ->set('data.closures', [['from' => '2026-11-10', 'to' => '2026-11-12', 'note' => 'Inventario'], ['from' => '2026-09-01', 'to' => '2026-09-02', 'note' => null]])
        ->call('save')->assertHasNoErrors();
    expect($this->venue->fresh()->closures)->toBe([['from' => '2026-11-10', 'to' => '2026-11-12', 'note' => 'Inventario']]);
});
