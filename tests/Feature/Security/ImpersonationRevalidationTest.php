<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\Web\ImpersonationController;
use App\Models\City;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

/**
 * L'impersonificazione vale finché chi la fa può ancora farla, e se ne fa una
 * alla volta. Prima un amministratore revocato o cancellato mentre
 * impersonava un collega restava dentro con i poteri del collega, e
 * un'impersonificazione annidata seguita da «interrompi» lasciava una
 * sessione piena come il collega, senza marcatore.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    City::factory()->padova()->create();
    $this->adminA = User::factory()->create();
    $this->adminA->assignRole(UserRole::Admin->value);
    $this->adminB = User::factory()->create();
    $this->adminB->assignRole(UserRole::Admin->value);
});

function avviaImpersonificazione(User $target): string
{
    return URL::temporarySignedRoute('impersonate.start', now()->addMinutes(5), ['user' => $target->id]);
}

it('chiude la sessione quando chi impersona perde i ruoli o viene cancellato', function (): void {
    $this->actingAs($this->adminA)->get(avviaImpersonificazione($this->adminB))->assertRedirect('/');
    $this->get('/admin/users')->assertOk();

    $a = User::query()->findOrFail($this->adminA->id);
    $a->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $a->delete();
    auth()->forgetGuards();

    $this->get('/admin/users')->assertForbidden();
    auth()->forgetGuards();
    expect(auth()->guard('web')->check())->toBeFalse()
        ->and(session(ImpersonationController::SESSION_KEY))->toBeNull();
    $this->get(route('account.profile.export'))->assertRedirect();
});

it('non fa avviare una seconda impersonificazione mentre se ne sta già facendo una', function (): void {
    $user = User::factory()->create();

    $this->actingAs($this->adminA)->get(avviaImpersonificazione($this->adminB))->assertRedirect('/');
    $this->get(avviaImpersonificazione($user))->assertForbidden();

    expect(auth()->id())->toBe($this->adminB->id)
        ->and(session(ImpersonationController::SESSION_KEY))->toBe($this->adminA->id);
    $this->get(route('account.profile.export'))->assertForbidden();

    $this->get(URL::signedRoute('impersonate.stop'))->assertRedirect('/admin');
    expect(auth()->id())->toBe($this->adminA->id)
        ->and(session(ImpersonationController::SESSION_KEY))->toBeNull();
});

it('lascia funzionare l\'impersonificazione di chi conserva i poteri', function (): void {
    $user = User::factory()->create();

    $this->actingAs($this->adminA)->get(avviaImpersonificazione($user))->assertRedirect('/');
    $this->get('/')->assertOk();
    expect(auth()->id())->toBe($user->id);
});
