<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\City;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
    $this->actingAs($this->admin);
});

it('distinguishes actual venue memberships from global roles and ordinary accounts', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::User->value);
    $owner = User::factory()->create();
    $editor = User::factory()->create();
    $unlinked = User::factory()->create();
    $unlinked->assignRole(UserRole::VenueOwner->value);
    $venue = Venue::factory()->approved()->create();
    $owner->venues()->attach($venue, ['role' => 'owner']);
    $editor->venues()->attach($venue, ['role' => 'editor']);

    Livewire::test(ListUsers::class)->set('activeTab', 'admins')
        ->assertCanSeeTableRecords([$this->admin])->assertCanNotSeeTableRecords([$user, $owner, $editor, $unlinked]);
    Livewire::test(ListUsers::class)->set('activeTab', 'owners')
        ->assertCanSeeTableRecords([$owner])->assertCanNotSeeTableRecords([$user, $editor, $unlinked]);
    Livewire::test(ListUsers::class)->set('activeTab', 'editors')
        ->assertCanSeeTableRecords([$editor])->assertCanNotSeeTableRecords([$owner, $unlinked]);
    Livewire::test(ListUsers::class)->set('activeTab', 'users')
        ->assertCanSeeTableRecords([$user])->assertCanNotSeeTableRecords([$this->admin, $owner, $editor, $unlinked]);
    Livewire::test(ListUsers::class)->set('activeTab', 'unlinked')
        ->assertCanSeeTableRecords([$unlinked])->assertCanNotSeeTableRecords([$owner, $user]);
});

it('filters reference city separately from managed venue cities without inferring personal data', function (): void {
    $city = City::factory()->create();
    $other = City::factory()->create();
    $resident = User::factory()->create(['city_id' => $city->id]);
    $unknown = User::factory()->create();
    $manager = User::factory()->create(['city_id' => $other->id]);
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $manager->venues()->attach($venue, ['role' => 'owner']);

    Livewire::test(ListUsers::class)->filterTable('city_id', $city->id)
        ->assertCanSeeTableRecords([$resident])->assertCanNotSeeTableRecords([$unknown, $manager]);
    Livewire::test(ListUsers::class)->filterTable('venue_city', $city->id)
        ->assertCanSeeTableRecords([$manager])->assertCanNotSeeTableRecords([$resident, $unknown]);
    Livewire::test(ListUsers::class)->filterTable('missing_city', true)
        ->assertCanSeeTableRecords([$unknown])->assertCanNotSeeTableRecords([$resident, $manager]);
    expect($unknown->fresh()->city_id)->toBeNull();
});

it('keeps ordinary users out of the directory', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::User->value);
    $this->actingAs($user)->get('/admin/users')->assertForbidden();
});
