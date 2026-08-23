<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\City;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * §9 — chi entra in `/admin`. Il pannello è della redazione: amministratore,
 * amministratore di sistema e moderatore. Nessun altro, nemmeno chi gestisce
 * un locale e ha un pannello proprio.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    City::factory()->padova()->create();
});

function userWithRole(UserRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

it('apre il pannello ai tre ruoli della redazione', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin')
        ->assertOk();
})->with([
    'amministratore' => UserRole::Admin,
    'amministratore di sistema' => UserRole::SuperAdmin,
    'moderatore' => UserRole::Moderator,
]);

it('chiude il pannello a chi non appartiene alla redazione', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin')
        ->assertForbidden();
})->with([
    'utente semplice' => UserRole::User,
    'referente del locale' => UserRole::VenueOwner,
    'collaboratore del locale' => UserRole::VenueEditor,
]);

it('manda chi non è entrato alla pagina di accesso', function (): void {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('non mostra ai moderatori il pulsante per creare una città', function (): void {
    // Il permesso `cities.manage` è dell'amministratore: il moderatore vede
    // l'elenco ma non può aggiungerne — e la verifica non sta nel pannello,
    // sta in `CityPolicy`, che Filament interroga da sé.
    $moderator = userWithRole(UserRole::Moderator);

    expect($moderator->can('create', City::class))->toBeFalse();

    $this->actingAs($moderator)->get('/admin/cities')->assertOk();
    $this->actingAs($moderator)->get('/admin/cities/create')->assertForbidden();
});

it('lascia creare una città a un amministratore', function (): void {
    $admin = userWithRole(UserRole::Admin);

    expect($admin->can('create', City::class))->toBeTrue();

    $this->actingAs($admin)->get('/admin/cities/create')->assertOk();
});
