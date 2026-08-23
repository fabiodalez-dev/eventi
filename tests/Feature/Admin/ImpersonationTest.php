<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\Web\ImpersonationController;
use App\Models\City;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * §9.2 — «Utenti: ruoli, impersonate».
 *
 * Il pericolo di questa funzione non è farla funzionare: è che funzioni verso
 * l'alto. Le prove in negativo contano quanto quella in positivo.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    City::factory()->padova()->create();
});

function actor(UserRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

it('lascia a un amministratore prendere l\'identità di un utente', function (): void {
    $admin = actor(UserRole::Admin);
    $target = actor(UserRole::User);

    $this->actingAs($admin)
        ->get(route('impersonate.start', ['user' => $target->getKey()]))
        ->assertRedirect('/');

    expect(auth()->id())->toBe($target->getKey())
        ->and(session(ImpersonationController::SESSION_KEY))->toBe($admin->getKey());
});

it('riporta all\'identità di partenza, e solo a quella', function (): void {
    $admin = actor(UserRole::Admin);
    $target = actor(UserRole::User);

    $this->actingAs($admin)->get(route('impersonate.start', ['user' => $target->getKey()]));

    $this->get(route('impersonate.stop'))->assertRedirect('/admin');

    expect(auth()->id())->toBe($admin->getKey())
        ->and(session(ImpersonationController::SESSION_KEY))->toBeNull();
});

it('nega l\'uscita a chi non stava impersonando nessuno', function (): void {
    $this->actingAs(actor(UserRole::User))
        ->get(route('impersonate.stop'))
        ->assertForbidden();
});

it('vieta al moderatore di impersonare: non ha quel permesso', function (): void {
    $moderator = actor(UserRole::Moderator);
    $target = actor(UserRole::User);

    expect($moderator->can('impersonate', $target))->toBeFalse();

    $this->actingAs($moderator)
        ->get(route('impersonate.start', ['user' => $target->getKey()]))
        ->assertForbidden();
});

it('vieta di impersonare verso l\'alto e verso se stessi', function (): void {
    $admin = actor(UserRole::Admin);
    $superAdmin = actor(UserRole::SuperAdmin);

    expect($admin->can('impersonate', $superAdmin))->toBeFalse()
        ->and($admin->can('impersonate', $admin))->toBeFalse()
        ->and($superAdmin->can('impersonate', $admin))->toBeTrue();

    $this->actingAs($admin)
        ->get(route('impersonate.start', ['user' => $superAdmin->getKey()]))
        ->assertForbidden();
});

it('manda chi non è autenticato all\'accesso della redazione', function (): void {
    $target = actor(UserRole::User);

    $this->get(route('impersonate.start', ['user' => $target->getKey()]))
        ->assertRedirect('/admin/login');

    $this->get(route('impersonate.stop'))->assertRedirect('/admin/login');
});
