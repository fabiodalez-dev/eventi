<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Il gradino fra `admin` e `super_admin` non si sale da soli.
 *
 * `UserPolicy::outranks()` difende i super amministratori esistenti, non il
 * gradino: `update()` diceva sì a un amministratore su qualunque scheda che
 * non fosse già di un super amministratore — **compresa la propria** — e il
 * menu dei ruoli offriva tutto l'enum. Due clic sulla propria scheda e la
 * barriera che ogni Policy protegge era scavalcata dall'interno.
 *
 * La difesa è `App\Filament\Support\RoleField`, e il controllo che conta è
 * quello al salvataggio: il menu ridotto è cortesia, non sicurezza — una
 * richiesta Livewire si forgia.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->superRole = Role::query()->where('name', UserRole::SuperAdmin->value)->firstOrFail();
    $this->adminRole = Role::query()->where('name', UserRole::Admin->value)->firstOrFail();
});

it('non lascia che un amministratore si promuova super amministratore', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $this->actingAs($admin);

    /* La richiesta è forgiata: si passa l'id del ruolo direttamente, come
       farebbe chi manda la chiamata Livewire a mano senza il menu. */
    Livewire::test(EditUser::class, ['record' => $admin->getKey()])
        ->fillForm(['roles' => [$this->superRole->getKey(), $this->adminRole->getKey()]])
        ->call('save');

    expect($admin->fresh()->hasRole(UserRole::SuperAdmin->value))->toBeFalse()
        ->and($admin->fresh()->hasRole(UserRole::Admin->value))->toBeTrue();
});

it('non lascia che un amministratore promuova qualcun altro', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $vittima = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $vittima->getKey()])
        ->fillForm(['roles' => [$this->superRole->getKey()]])
        ->call('save');

    expect($vittima->fresh()->hasRole(UserRole::SuperAdmin->value))->toBeFalse();
});

/*
 * Il contrappeso: chi il potere ce l'ha deve continuare a esercitarlo,
 * altrimenti la prima cosa che si fa è togliere la difesa.
 */
it('lascia a un super amministratore il potere di conferire il proprio ruolo', function (): void {
    $super = User::factory()->create();
    $super->assignRole(UserRole::SuperAdmin->value);
    $destinatario = User::factory()->create();
    $this->actingAs($super);

    Livewire::test(EditUser::class, ['record' => $destinatario->getKey()])
        ->fillForm(['roles' => [$this->superRole->getKey()]])
        ->call('save');

    expect($destinatario->fresh()->hasRole(UserRole::SuperAdmin->value))->toBeTrue();
});

/*
 * L'altra metà della difesa, che il primo tentativo di questo test ha
 * scoperto: un amministratore non arriva nemmeno ad APRIRE la scheda di un
 * super amministratore, perché `UserPolicy::outranks()` nega `update` e la
 * pagina non si monta.
 *
 * Vale la pena tenerlo scritto: è la ragione per cui la conservazione dei
 * ruoli non conferibili in `RoleField` resta difesa in profondità e non un
 * percorso che si attraversa dal pannello.
 */
it('non lascia a un amministratore nemmeno aprire la scheda di un super amministratore', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $super = User::factory()->create();
    $super->assignRole(UserRole::SuperAdmin->value);
    $this->actingAs($admin);

    expect($admin->can('update', $super))->toBeFalse();

    $this->get(EditUser::getUrl(['record' => $super->getKey()], panel: 'admin'))->assertForbidden();
});
