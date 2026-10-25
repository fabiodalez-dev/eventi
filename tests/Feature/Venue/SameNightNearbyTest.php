<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Models\User;
use App\Models\Venue;
use App\Services\Events\SameNightNearby;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

afterEach(function (): void {
    Carbon\Carbon::setTestNow();
    Filament\Facades\Filament::setTenant(null);
    Filament\Facades\Filament::setCurrentPanel(null);
});

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->other = testCategory(['name' => 'Teatro']);
    freezeLocal($this->city, '2026-10-24 12:00');
    $this->own = Venue::factory()->approved()->at(45.4064, 11.8768)->create(['city_id' => $this->city->id]);
    $this->near = Venue::factory()->approved()->at(45.4080, 11.8790)->create(['city_id' => $this->city->id, 'name' => 'Bar Vicino']);
    $this->far = Venue::factory()->approved()->at(45.5500, 11.5500)->create(['city_id' => $this->city->id, 'name' => 'Lontano']);
});

function nearbyAround(object $test, string $start, ?string $end = null, ?int $category = null): array
{
    return app(SameNightNearby::class)->around($test->city, 45.4064, 11.8768, localInstant($test->city, $start),
        $end === null ? null : localInstant($test->city, $end), $test->own->id, $category);
}

it('lists published dates of other venues that overlap the time, nearest first, with the same category marked', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', '2026-10-30 23:30', event: ['title' => 'Jazz al bar'], venue: $this->near);
    occurrenceAtLocal($this->city, $this->other, '2026-10-30 20:30', null, event: ['title' => 'Prosa'], venue: $this->near);
    occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', null, event: ['title' => 'Troppo lontano'], venue: $this->far);
    occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', null, event: ['title' => 'In casa'], venue: $this->own);
    occurrenceAtLocal($this->city, $this->category, '2026-10-30 18:00', '2026-10-30 19:30', event: ['title' => 'Aperitivo finito'], venue: $this->near);
    occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', null, event: ['title' => 'Bozza', 'status' => 'draft'], venue: $this->near);
    occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', null, occurrence: ['status' => 'cancelled'], event: ['title' => 'Annullato'], venue: $this->near);

    $items = nearbyAround($this, '2026-10-30 21:30', null, $this->category->id);

    expect(array_column($items, 'title'))->toEqualCanonicalizing(['Jazz al bar', 'Prosa'])
        ->and(collect($items)->firstWhere('title', 'Jazz al bar'))->toMatchArray(['time' => '21:00', 'place' => 'Bar Vicino', 'same_category' => true])
        ->and(collect($items)->firstWhere('title', 'Prosa')['same_category'])->toBeFalse()
        ->and($items[0]['distance_m'])->toBeGreaterThan(0)->toBeLessThan(2000);
});

it('treats a date without an end as three hours long', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-10-30 23:50', null, event: ['title' => 'Dopo mezzanotte'], venue: $this->near);

    expect(array_column(nearbyAround($this, '2026-10-30 21:00'), 'title'))->toBe(['Dopo mezzanotte'])
        ->and(nearbyAround($this, '2026-10-30 18:00', '2026-10-30 20:00'))->toBe([]);
});

it('shows the warning in the venue wizard only when something overlaps', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', null, event: ['title' => 'Jazz al bar'], venue: $this->near);
    (new RolesAndPermissionsSeeder)->run();
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $owner->venues()->attach($this->own, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => now()]);
    // La pagina vera prima: è la richiesta HTTP a far registrare a Filament le
    // restrizioni del pannello sui modelli, che una prova diretta salterebbe.
    $this->actingAs($owner)->get('/gestione/'.$this->own->slug.'/eventi/nuovo')->assertOk();

    Livewire::test(CreateEvent::class)
        ->fillForm(['title' => 'Serata', 'starts_at' => CarbonImmutable::parse('2026-10-30 21:30', 'Europe/Rome')->utc()->format('Y-m-d H:i:s')])
        ->assertSee(__('manage.same_night.title'))->assertSee('Jazz al bar')
        ->fillForm(['starts_at' => CarbonImmutable::parse('2026-11-02 21:30', 'Europe/Rome')->utc()->format('Y-m-d H:i:s')])
        ->assertDontSee('Jazz al bar');
});
