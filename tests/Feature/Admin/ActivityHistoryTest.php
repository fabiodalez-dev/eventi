<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\ActivityRelationManager;
use App\Filament\Admin\Resources\Venues\Pages\EditVenue;
use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->admin = User::factory()->create(['name' => 'Redazione Cronologia']);
    $this->admin->assignRole(UserRole::Admin->value);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
});

it('renders editorial before and after values with actor and local time and escapes unsafe text', function (string $model, string $page): void {
    $record = $model::factory()->create(['rejection_reason' => 'Motivo precedente']);
    $this->travelTo(now()->setDate(2026, 7, 1)->setTime(10, 0));
    $payload = '<img src=x onerror=alert(1)>Nuova motivazione';
    $record->update(['rejection_reason' => $payload]);
    $activity = $record->activitiesAsSubject()->where('event', 'updated')->latest('id')->firstOrFail();
    expect($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->attribute_changes['old']['rejection_reason'])->toBe('Motivo precedente');
    Livewire::test(ActivityRelationManager::class, ['ownerRecord' => $record, 'pageClass' => $page])
        ->assertCanSeeTableRecords([$activity])
        ->assertSee('Motivo precedente')->assertSee($payload)
        ->assertDontSeeHtml($payload)->assertSee('Redazione Cronologia')->assertSee('01/07/2026 12:00');
})->with([[Event::class, EditEvent::class], [Venue::class, EditVenue::class]]);

it('shows venue history and does not label a removed actor as an automatic system action', function (): void {
    expect(VenueResource::getRelations())->toContain(ActivityRelationManager::class);
    $venue = Venue::factory()->create();
    $activity = activity()->performedOn($venue)->withChanges(['attributes' => ['rejection_reason' => 'Nota autore rimosso']])->log('updated');
    // Preserve the audit reference without depending on account deletion cascades.
    $activity->update(['causer_type' => $this->admin->getMorphClass(), 'causer_id' => 999999999]);
    Livewire::test(ActivityRelationManager::class, ['ownerRecord' => $venue, 'pageClass' => EditVenue::class])
        ->assertSee('Account non più disponibile')->assertSee('Nota autore rimosso');
});

it('does not expose the administrative history pages to venue managers', function (): void {
    $venue = Venue::factory()->approved()->create();
    $event = Event::factory()->create(['venue_id' => $venue->id]);
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $owner->venues()->attach($venue, ['role' => 'owner']);
    $this->actingAs($owner);
    $this->get('/admin/events/'.$event->getRouteKey().'/edit')->assertForbidden();
    $this->get('/admin/venues/'.$venue->getRouteKey().'/edit')->assertForbidden();
});
