<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Services\Account\MobileTokenIssuer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * A09:2021 — un attaccante deve lasciare una riga.
 *
 * `activity()` era già in uso per il ticketing e per le modifiche editoriali,
 * e non per le cose che si vanno a cercare dopo un incidente. La prima domanda
 * — «da quando, e da dove?» — non aveva risposta.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
});

function righeSicurezza(string $evento): Collection
{
    return Activity::query()->where('log_name', 'sicurezza')->where('description', $evento)->get();
}

it('registra un tentativo di accesso fallito, con l’indirizzo di chi prova', function (): void {
    $utente = User::factory()->create(['password' => Hash::make('password-giusta-1')]);

    $this->post(route('account.login.store'), [
        'email' => $utente->email,
        'password' => 'password-sbagliata',
    ]);

    $righe = righeSicurezza('accesso_fallito');

    expect($righe)->toHaveCount(1)
        ->and($righe->first()->subject_id)->toBe($utente->getKey())
        ->and($righe->first()->properties['email'])->toBe($utente->email)
        ->and($righe->first()->properties)->toHaveKey('ip');
});

/**
 * La scelta di privacy dentro la difesa: un tentativo su un indirizzo che non
 * esiste è quasi sempre un errore di battitura o una scansione. Registrarlo
 * vorrebbe dire conservare indirizzi di persone che con questo sito non hanno
 * niente a che fare — un archivio di dati personali creato per difendersi da
 * un rumore.
 */
it('non conserva l’indirizzo quando l’account non esiste', function (): void {
    $this->post(route('account.login.store'), [
        'email' => 'estraneo@altrove.test',
        'password' => 'qualunque',
    ]);

    $riga = righeSicurezza('accesso_fallito')->first();

    expect($riga)->not->toBeNull()
        ->and($riga->properties)->not->toHaveKey('email')
        ->and($riga->properties['bersaglio'])->toBe('account inesistente')
        ->and(json_encode($riga->properties))->not->toContain('estraneo@altrove.test');
});

it('non scrive mai la password nel registro', function (): void {
    $utente = User::factory()->create(['password' => Hash::make('password-giusta-1')]);

    $this->post(route('account.login.store'), [
        'email' => $utente->email,
        'password' => 'segretissima-da-non-registrare',
    ]);

    $tutto = Activity::query()->get()->map(fn (Activity $riga): string => json_encode($riga->properties) ?: '')->implode(' ');

    expect($tutto)->not->toContain('segretissima-da-non-registrare');
});

it('registra l’emissione di un token, senza il token', function (): void {
    $utente = User::factory()->create();

    $esito = app(MobileTokenIssuer::class)->issue($utente, 'Telefono di prova');

    $riga = righeSicurezza('token_emesso')->first();

    expect($riga)->not->toBeNull()
        ->and($riga->subject_id)->toBe($utente->getKey())
        ->and($riga->properties['dispositivo'])->toBe('Telefono di prova')
        ->and(json_encode($riga->properties))->not->toContain($esito['token']);
});

/**
 * «Chi ha dato l'amministrazione a chi» è la prima domanda di qualunque
 * indagine su un pannello.
 */
it('registra un cambio di ruolo con il prima e il dopo', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $super = User::factory()->create();
    $super->assignRole(UserRole::SuperAdmin->value);
    $destinatario = User::factory()->create();
    $this->actingAs($super);

    Livewire::test(EditUser::class, ['record' => $destinatario->getKey()])
        ->fillForm(['roles' => [Role::query()->where('name', UserRole::Admin->value)->firstOrFail()->getKey()]])
        ->call('save');

    $riga = righeSicurezza('ruoli_cambiati')->first();

    expect($riga)->not->toBeNull()
        ->and($riga->subject_id)->toBe($destinatario->getKey())
        ->and($riga->causer_id)->toBe($super->getKey())
        ->and($riga->properties['dopo'])->toContain(UserRole::Admin->value);
});

/*
 * Il contrappeso: il registro non deve riempirsi a ogni salvataggio del
 * profilo, altrimenti diventa illeggibile e nessuno lo guarda più.
 */
it('non scrive nulla quando i ruoli non cambiano', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $super = User::factory()->create();
    $super->assignRole(UserRole::SuperAdmin->value);
    $destinatario = User::factory()->create();
    $destinatario->assignRole(UserRole::User->value);
    $this->actingAs($super);

    $ruoloUtente = Role::query()->where('name', UserRole::User->value)->firstOrFail();

    Livewire::test(EditUser::class, ['record' => $destinatario->getKey()])
        ->fillForm(['roles' => [$ruoloUtente->getKey()]])
        ->call('save');

    expect(righeSicurezza('ruoli_cambiati'))->toHaveCount(0);
});
