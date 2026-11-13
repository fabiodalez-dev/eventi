<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Http\Controllers\Web\ImpersonationController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Flusso: l'assistenza entra nei panni di una persona per vedere quello che
 * vede lei, prova (senza riuscirci) ad agire a suo nome, e torna al proprio
 * account. Il secondo percorso raccoglie tutte le porte che devono restare
 * chiuse: link non firmati o scaduti, ruoli senza il permesso, verso l'alto.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = User::factory()->create(['name' => 'Assistenza Redazione']);
    $this->admin->assignRole(UserRole::Admin->value);

    $this->target = User::factory()->create(['name' => 'Giulia Rossi', 'email_verified_at' => now()]);
    $this->target->assignRole(UserRole::User->value);
});

function gstLogSicurezza(string $evento): int
{
    return Activity::query()->where('log_name', 'sicurezza')->where('description', $evento)->count();
}

it('entra nei panni di un utente, non agisce a suo nome e torna al proprio account', function (): void {
    // 1. Dall'elenco utenti, l'azione rimanda alla rotta firmata.
    $this->actingAs($this->admin)->get('/admin/users')->assertOk()->assertSee('Giulia Rossi');
    Livewire::test(ListUsers::class)
        ->assertTableActionVisible('impersonate', $this->target)
        ->callTableAction('impersonate', $this->target)
        ->assertRedirectContains('/impersona/'.$this->target->getKey());

    // 2. Seguendo il collegamento firmato si diventa la persona.
    $this->get(URL::temporarySignedRoute('impersonate.start', now()->addMinutes(5), ['user' => $this->target->getKey()]))
        ->assertRedirect('/');
    expect(auth()->id())->toBe($this->target->getKey())
        ->and(session(ImpersonationController::SESSION_KEY))->toBe($this->admin->getKey());

    // Il sito lo dice in ogni pagina, con il ritorno a portata di mano.
    $this->get('/eventi')->assertOk()
        ->assertSee(__('admin.notifications.impersonating', ['name' => 'Giulia Rossi']))
        ->assertSee(__('admin.actions.stop_impersonating'));

    // 3. Guardare il profilo si può; cambiarlo o scaricarne i dati no.
    $this->get(route('account.profile'))->assertOk();
    $this->patch(route('account.profile.update'), ['name' => 'Nome scelto da altri'])->assertForbidden();
    $this->get(route('account.profile.export'))->assertForbidden();
    expect($this->target->fresh()->name)->toBe('Giulia Rossi');

    // 4. L'uscita riporta all'identità di partenza e alla redazione.
    $this->get(URL::signedRoute('impersonate.stop'))->assertRedirect('/admin');
    expect(auth()->id())->toBe($this->admin->getKey())
        ->and(session(ImpersonationController::SESSION_KEY))->toBeNull();
    $this->get('/admin')->assertOk();

    // Il registro di sicurezza conserva inizio e fine, con chi li ha causati.
    $inizio = Activity::query()->where('log_name', 'sicurezza')->where('description', 'impersonificazione_iniziata')->sole();
    $fine = Activity::query()->where('log_name', 'sicurezza')->where('description', 'impersonificazione_terminata')->sole();
    expect($inizio->subject_id)->toBe($this->target->getKey())
        ->and($inizio->causer_id)->toBe($this->admin->getKey())
        ->and($fine->subject_id)->toBe($this->target->getKey())
        ->and($fine->causer_id)->toBe($this->admin->getKey());
});

it('tiene chiuse le porte dell\'impersonificazione: firma mancante o scaduta, ruolo senza permesso, verso l\'alto', function (): void {
    $this->actingAs($this->admin);

    // Senza firma, o con la firma scaduta, il cambio di identità non avviene.
    $this->get('/impersona/'.$this->target->getKey())->assertForbidden();
    $scaduto = URL::temporarySignedRoute('impersonate.start', now()->addMinutes(5), ['user' => $this->target->getKey()]);
    $this->travel(6)->minutes();
    $this->get($scaduto)->assertForbidden();
    expect(auth()->id())->toBe($this->admin->getKey());

    // Verso un super amministratore, o verso se stessi, nemmeno con la firma.
    $super = User::factory()->create();
    $super->assignRole(UserRole::SuperAdmin->value);
    $this->get(URL::signedRoute('impersonate.start', ['user' => $super->getKey()]))->assertForbidden();
    $this->get(URL::signedRoute('impersonate.start', ['user' => $this->admin->getKey()]))->assertForbidden();
    Livewire::test(ListUsers::class)->assertTableActionHidden('impersonate', $super);

    // Il moderatore non ha il permesso: niente azione e niente rotta.
    $moderator = User::factory()->create();
    $moderator->assignRole(UserRole::Moderator->value);
    $this->actingAs($moderator);
    Livewire::test(ListUsers::class)->assertTableActionHidden('impersonate', $this->target);
    $this->get(URL::signedRoute('impersonate.start', ['user' => $this->target->getKey()]))->assertForbidden();

    // Chi non stava impersonando non può «tornare» a un'altra identità,
    // e l'uscita non firmata non funziona nemmeno per chi sta impersonando.
    $this->actingAs($this->target)->get(URL::signedRoute('impersonate.stop'))->assertForbidden();
    $this->get('/impersona/interrompi')->assertForbidden();

    expect(auth()->id())->toBe($this->target->getKey())
        ->and(gstLogSicurezza('impersonificazione_iniziata'))->toBe(0)
        ->and(gstLogSicurezza('impersonificazione_terminata'))->toBe(0);
});
