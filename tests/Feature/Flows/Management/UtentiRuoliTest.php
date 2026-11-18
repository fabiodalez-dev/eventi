<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * Flusso: la gestione degli utenti dalla redazione. Un amministratore
 * promuove una persona a moderatrice e poi la riporta indietro; chi sta più
 * in basso prova a salire e viene fermato, dal pannello e dal salvataggio.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->roleId = fn (UserRole $role): int => (int) Role::query()->where('name', $role->value)->value('id');

    $this->admin = User::factory()->create(['name' => 'Amministratrice Prova']);
    $this->admin->assignRole(UserRole::Admin->value);
});

function gstRuoliCambiati(User $subject): int
{
    return Activity::query()
        ->where('log_name', 'sicurezza')
        ->where('description', 'ruoli_cambiati')
        ->where('subject_id', $subject->getKey())
        ->count();
}

it('promuove un utente a moderatore dal pannello, gli apre la redazione e lo registra; poi lo riporta indietro', function (): void {
    $target = User::factory()->create(['name' => 'Marta Volontaria']);
    $target->assignRole(UserRole::User->value);

    // Prima della promozione la redazione le è chiusa.
    $this->actingAs($target)->get('/admin')->assertForbidden();

    // L'amministratrice la trova nell'elenco e apre la scheda.
    $this->actingAs($this->admin)->get('/admin/users')->assertOk()->assertSee('Marta Volontaria');
    $this->get(UserResource::getUrl('edit', ['record' => $target], panel: 'admin'))->assertOk();

    Livewire::test(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['roles' => [($this->roleId)(UserRole::Moderator)]])
        ->call('save')
        ->assertHasNoFormErrors();

    $target = $target->fresh();
    expect($target->hasRole(UserRole::Moderator->value))->toBeTrue()
        ->and($target->hasRole(UserRole::User->value))->toBeFalse();

    // Il registro di sicurezza dice chi ha dato cosa a chi.
    $log = Activity::query()->where('log_name', 'sicurezza')->where('description', 'ruoli_cambiati')->sole();
    expect($log->subject_id)->toBe($target->getKey())
        ->and($log->causer_id)->toBe($this->admin->getKey())
        ->and($log->properties['prima'])->toBe([UserRole::User->value])
        ->and($log->properties['dopo'])->toBe([UserRole::Moderator->value]);

    // Adesso la redazione e la coda delle segnalazioni le sono aperte.
    $this->actingAs($target)->get('/admin')->assertOk();
    $this->get('/admin/reports')->assertOk();

    // La promozione si annulla con lo stesso gesto.
    $this->actingAs($this->admin);
    Livewire::test(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['roles' => [($this->roleId)(UserRole::User)]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->hasRole(UserRole::Moderator->value))->toBeFalse()
        ->and(gstRuoliCambiati($target))->toBe(2);
    $this->actingAs($target->fresh())->get('/admin')->assertForbidden();
});

it('respinge chi prova a salire di grado: moderatore sulle schede, amministratore verso super amministratore, gestore nella redazione', function (): void {
    $plain = User::factory()->create(['name' => 'Utente Qualunque']);
    $plain->assignRole(UserRole::User->value);

    // Il moderatore vede l'elenco ma non può modificare nessuno.
    $moderator = User::factory()->create();
    $moderator->assignRole(UserRole::Moderator->value);
    $this->actingAs($moderator)->get('/admin/users')->assertOk()->assertSee('Utente Qualunque');
    $this->get(UserResource::getUrl('edit', ['record' => $plain], panel: 'admin'))->assertForbidden();
    $this->get(UserResource::getUrl('edit', ['record' => $moderator], panel: 'admin'))->assertForbidden();

    // L'amministratore che si promuove da solo con una richiesta forgiata
    // resta amministratore, e il registro non annota un cambio che non c'è.
    $this->actingAs($this->admin);
    Livewire::test(EditUser::class, ['record' => $this->admin->getKey()])
        ->fillForm(['roles' => [($this->roleId)(UserRole::SuperAdmin), ($this->roleId)(UserRole::Admin)]])
        ->call('save');
    expect($this->admin->fresh()->hasRole(UserRole::SuperAdmin->value))->toBeFalse()
        ->and($this->admin->fresh()->hasRole(UserRole::Admin->value))->toBeTrue()
        ->and(gstRuoliCambiati($this->admin))->toBe(0);

    // Nemmeno promuovendo qualcun altro.
    Livewire::test(EditUser::class, ['record' => $plain->getKey()])
        ->fillForm(['roles' => [($this->roleId)(UserRole::SuperAdmin)]])
        ->call('save');
    expect($plain->fresh()->hasRole(UserRole::SuperAdmin->value))->toBeFalse();

    // E la scheda di un super amministratore non si apre.
    $super = User::factory()->create();
    $super->assignRole(UserRole::SuperAdmin->value);
    $this->get(UserResource::getUrl('edit', ['record' => $super], panel: 'admin'))->assertForbidden();

    // Chi gestisce un locale non entra nell'anagrafica.
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $owner->venues()->attach(Venue::factory()->approved()->create(), ['role' => 'owner']);
    $this->actingAs($owner)->get('/admin/users')->assertForbidden();

    // Il contrappeso: il super amministratore può conferire il proprio ruolo.
    $this->actingAs($super);
    Livewire::test(EditUser::class, ['record' => $this->admin->getKey()])
        ->fillForm(['roles' => [($this->roleId)(UserRole::SuperAdmin)]])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($this->admin->fresh()->hasRole(UserRole::SuperAdmin->value))->toBeTrue()
        ->and(gstRuoliCambiati($this->admin))->toBe(1);
});
