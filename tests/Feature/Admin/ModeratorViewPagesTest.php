<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Admin\Resources\Venues\Pages\ListVenues;
use App\Filament\Admin\Resources\Venues\Pages\ViewVenue;
use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Il moderatore può leggere e decidere, non modificare: apre la scheda di un
 * evento o di un locale in sola lettura (prima riceveva un 403, perché
 * l'unica pagina era il modulo di modifica), pubblica dalla scheda o dalla
 * riga dell'elenco, e la modifica gli resta chiusa.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->city = City::factory()->padova()->create();
    freezeLocal($this->city, '2026-11-10 12:00');
    $this->category = Category::factory()->create(['default_duration_minutes' => 120]);
    $this->venue = Venue::factory()->create(['city_id' => $this->city->id, 'status' => VenueStatus::Pending, 'name' => 'Spazio in attesa']);
    $this->pending = Event::factory()->create([
        'city_id' => $this->city->id,
        'category_id' => $this->category->id,
        'venue_id' => $this->venue->id,
        'status' => EventStatus::Pending,
        'title' => 'Concerto da approvare',
        'description' => '<p>Una serata da leggere prima di pubblicare.</p>',
    ]);
    EventOccurrence::factory()->create(['event_id' => $this->pending->id, 'starts_at' => '2026-11-20 19:30:00', 'ends_at' => null]);

    $this->moderator = User::factory()->create();
    $this->moderator->assignRole(UserRole::Moderator->value);
    $this->actingAs($this->moderator);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('opens the read-only event page for a moderator and keeps the edit page closed', function (): void {
    $this->get(EventResource::getUrl('view', ['record' => $this->pending]))->assertOk()
        ->assertSee('Concerto da approvare')
        ->assertSee('Una serata da leggere prima di pubblicare.')
        ->assertSee('data-event-read-view', false)
        ->assertSee('data-event-moderation', false)
        // L'ora della serata è quella di Padova: 19:30 UTC sono le 20:30.
        ->assertSee('20/11/2026, 20:30')
        ->assertSee(__('admin.actions.publish'))
        ->assertDontSee('wire:model="data.title"', false)
        ->assertDontSee(EventResource::getUrl('edit', ['record' => $this->pending]), false);

    $this->get(EventResource::getUrl('edit', ['record' => $this->pending]))->assertForbidden();
});

it('lets the moderator publish from the read-only page header', function (): void {
    Livewire::test(ViewEvent::class, ['record' => $this->pending->getRouteKey()])
        ->assertActionVisible('publish')
        ->callAction('publish')
        ->assertHasNoActionErrors();

    expect($this->pending->fresh()->status)->toBe(EventStatus::Published);
});

it('lets the moderator publish from the row of the pending tab', function (): void {
    Livewire::test(ListEvents::class)
        ->set('activeTab', 'pending')
        ->assertCanSeeTableRecords([$this->pending])
        ->assertTableActionHidden('edit', $this->pending)
        ->assertTableActionVisible('view', $this->pending)
        ->assertTableActionVisible('publish', $this->pending)
        ->callTableAction('publish', $this->pending)
        ->assertHasNoTableActionErrors();

    expect($this->pending->fresh()->status)->toBe(EventStatus::Published);
});

it('opens the read-only venue page with its moderation actions', function (): void {
    $this->get(VenueResource::getUrl('view', ['record' => $this->venue]))->assertOk()
        ->assertSee('Spazio in attesa')
        ->assertSee('data-venue-read-view', false)
        ->assertSee('Concerto da approvare')
        ->assertSee(__('admin.actions.approve'));

    $this->get(VenueResource::getUrl('edit', ['record' => $this->venue]))->assertForbidden();

    Livewire::test(ViewVenue::class, ['record' => $this->venue->getRouteKey()])
        ->callAction('approve')
        ->assertHasNoActionErrors();

    expect($this->venue->fresh()->status)->toBe(VenueStatus::Approved);
});

it('keeps the moderation row actions in the venue list', function (): void {
    Livewire::test(ListVenues::class)
        ->assertCanSeeTableRecords([$this->venue])
        ->assertTableActionHidden('edit', $this->venue)
        ->assertTableActionVisible('view', $this->venue)
        ->assertTableActionVisible('approve', $this->venue);
});

it('refuses the read-only pages to someone outside the editorial staff', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(EventResource::getUrl('view', ['record' => $this->pending]))->assertForbidden();
    $this->get(VenueResource::getUrl('view', ['record' => $this->venue]))->assertForbidden();
});

it('offers the sponsorship shortcut to an admin on a published event with a future date', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $this->actingAs($admin);
    $this->pending->update(['status' => EventStatus::Published, 'published_at' => now()]);

    Livewire::test(ViewEvent::class, ['record' => $this->pending->getRouteKey()])
        ->assertActionVisible('sponsor')
        ->assertActionHidden('publish');

    $this->actingAs($this->moderator);
    Livewire::test(ViewEvent::class, ['record' => $this->pending->getRouteKey()])
        ->assertActionHidden('sponsor');
});
