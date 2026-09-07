<?php

use App\Enums\UserRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    testCity();
});

it('offers all five mobile destinations and sends guests to login from profile', function () {
    $this->get('/accedi')->assertOk()->assertSee('data-mobile-navigation', false)
        ->assertSee(route('events.index'))->assertSee(route('map.index'))
        ->assertSee(route('search'))->assertSee(route('account.saved'))->assertSee(route('account.profile'));
    $this->get(route('account.profile'))->assertRedirect(route('login'));
});

it('shows the editorial panel in the authenticated profile only to staff', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('account.profile'))->assertOk()->assertDontSee('href="'.url('/admin').'"', false);
    Role::findOrCreate(UserRole::Admin->value, 'web');
    $user->assignRole(UserRole::Admin->value);
    $this->actingAs($user)->get(route('account.profile'))->assertOk()->assertSee('href="'.url('/admin').'"', false);
});
