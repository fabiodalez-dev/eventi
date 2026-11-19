<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    testCity();
});

it('offers all seven mobile destinations and sends guests to login from profile', function () {
    $this->get('/accedi')->assertOk()->assertSee('data-mobile-navigation', false)
        ->assertSee(route('home'))->assertSee(route('events.index'))->assertSee(route('calendar.index'))->assertSee(route('map.index'))
        ->assertSee(route('search'))->assertSee(route('account.saved'))->assertSee(route('account.profile'));
    $this->get(route('account.profile'))->assertRedirect(route('login'));
});

it('shows icons only, each named for screen readers', function () {
    $html = $this->get('/accedi')->assertOk()->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $links = (new DOMXPath($dom))->query('//nav[@data-mobile-navigation]//a');

    expect($links->length)->toBe(7);

    $labels = [];
    foreach ($links as $link) {
        expect(trim($link->getAttribute('aria-label')))->not->toBe('')
            ->and($link->getAttribute('title'))->toBe($link->getAttribute('aria-label'))
            ->and(trim($link->textContent))->toBe('');
        $labels[] = $link->getAttribute('aria-label');
    }

    expect($labels)->toBe([
        __('ui.nav.home'), __('ui.nav.events'), __('ui.nav.calendar'), __('ui.nav.map'),
        __('ui.nav.search'), __('account.nav.saved'), __('account.nav.profile'),
    ]);
});

it('marks the calendar as the active destination on the calendar page', function () {
    $html = $this->get(route('calendar.index'))->assertOk()->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $active = (new DOMXPath($dom))->query('//nav[@data-mobile-navigation]//a[@aria-current="page"]');

    expect($active->length)->toBe(1)
        ->and($active->item(0)->getAttribute('href'))->toBe(route('calendar.index'));
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
