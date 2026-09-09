<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    testCity();
});

it('offers all six mobile destinations and sends guests to login from profile', function () {
    $this->get('/accedi')->assertOk()->assertSee('data-mobile-navigation', false)
        ->assertSee(route('home'))->assertSee(route('events.index'))->assertSee(route('map.index'))
        ->assertSee(route('search'))->assertSee(route('account.saved'))->assertSee(route('account.profile'));
    $this->get(route('account.profile'))->assertRedirect(route('login'));
});

it('keeps home and events as distinct active destinations', function (string $route) {
    $html = $this->get(route($route))->assertOk()->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $active = $xpath->query('//nav[@data-mobile-navigation]//a[@aria-current="page"]');
    expect($active->length)->toBe(1)
        ->and($active->item(0)->getAttribute('href'))->toBe(route($route));
})->with(['home', 'events.index']);

it('uses balanced sharing rows and generous touch targets', function () {
    $html = Blade::render('<x-share-links url="https://example.test/event" title="Evento" />');
    expect($html)->toContain('grid-cols-2', 'sm:grid-cols-4', 'min-h-12')
        ->not->toContain('flex-wrap');
});

it('shows the editorial panel in the authenticated profile only to staff', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('account.profile'))->assertOk()->assertDontSee('href="'.url('/admin').'"', false);
    Role::findOrCreate(UserRole::Admin->value, 'web');
    $user->assignRole(UserRole::Admin->value);
    $this->actingAs($user)->get(route('account.profile'))->assertOk()->assertSee('href="'.url('/admin').'"', false);
});
