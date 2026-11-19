<?php

declare(strict_types=1);

use App\Models\Organizer;
use Filament\Livewire\Topbar;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Il menu della persona, uguale nei tre pannelli: chi è dentro, «Area
 * personale», «Tema chiaro o scuro», «Esci». Le parole sono quelle del sito.
 */

it('dice chi è dentro e ha le stesse quattro righe nei tre pannelli', function (string $panel): void {
    $scenario = VenueIsolationScenario::make();
    $user = $panel === 'admin' ? $scenario->admin : $scenario->ownerA;
    $user->forceFill(['name' => 'Giulia Portici'])->save();
    $url = match ($panel) {
        'admin' => '/admin',
        'venue' => '/gestione/'.$scenario->venueA->slug,
        'organizer' => '/organizza/'.Organizer::create(['name' => 'Collettivo Portici', 'owner_id' => $user->id, 'city_id' => $scenario->city->id, 'is_active' => true])->slug,
    };

    $this->actingAs($user->fresh())->get($url)->assertOk()
        ->assertSee('data-user-identity', false)
        ->assertSeeInOrder(['Giulia Portici', $user->email, __('pannelli-impianto.user_menu.account'), __('pannelli-impianto.user_menu.to_dark'), __('pannelli-impianto.user_menu.logout')])
        ->assertSee(__('pannelli-impianto.user_menu.signed_in_as'))
        ->assertSee(route('account.profile'), false)
        ->assertDontSee('Disconnetti')
        ->assertDontSee('Il mio profilo')
        ->assertDontSee(__('manage.access.menu'));
})->with(['admin', 'venue', 'organizer']);

it('porta dal pannello all’area personale, che riporta al pannello', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->ownerA)->get('/gestione/'.$scenario->venueA->slug)->assertOk();

    $this->get(route('account.profile'))->assertOk()->assertSee('href="'.url('/gestione').'"', false);
    $this->get(url('/gestione'))->assertRedirectContains('/gestione/'.$scenario->venueA->slug);
});

it('fa uscire dal pannello', function (): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->ownerA)->get('/gestione/'.$scenario->venueA->slug)->assertOk();

    $this->post(route('filament.venue.auth.logout'))->assertRedirect();
    $this->assertGuest();
});

it('cambia il tema dal menu senza uscire dal pannello, e la scelta vale anche per il sito', function (): void {
    $scenario = VenueIsolationScenario::make();
    $admin = $scenario->admin;
    $admin->forceFill(['appearance' => 'dark'])->save();

    $this->actingAs($admin)->get('/admin')->assertOk()
        ->assertSee(__('pannelli-impianto.user_menu.to_light'))
        ->assertSeeHtml('data-panel-theme="dark"');

    Livewire::test(Topbar::class)->callAction('appearance');
    expect($admin->fresh()->appearance)->toBe('light');

    $this->get('/admin')->assertSee(__('pannelli-impianto.user_menu.to_dark'))->assertSeeHtml('data-panel-theme="light"');
});
