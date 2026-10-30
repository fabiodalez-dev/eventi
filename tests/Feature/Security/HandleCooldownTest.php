<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\Community\CommunityModeration;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * L'attesa fra un cambio di nome utente e il successivo (D64, rinvio chiuso).
 *
 * Il profilo prenota il nome attuale e l'ultimo lasciato, ma si poteva
 * cambiare nome a raffica: i nomi giravano fra profili e un collegamento
 * vecchio portava ogni giorno da una persona diversa. Ora fra due cambi
 * passano trenta giorni; il primo nome non conta, la redazione non aspetta.
 */

/** Una persona che può partecipare alla community, con o senza profilo. */
function personaNomeUtente(?string $handle = 'nome_iniziale'): User
{
    $user = User::factory()->create(['timezone' => 'Europe/Rome']);
    $user->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'attesa-'.$user->id)])->save();
    if ($handle !== null) {
        $user->communityProfile()->create(['handle' => $handle, 'display_name' => 'Persona '.$user->id, 'visibility' => 'public']);
    }

    return $user->fresh();
}

/** @return array<string, mixed> */
function datiProfilo(string $handle, string $nome = 'Titolare'): array
{
    return ['handle' => $handle, 'display_name' => $nome, 'visibility' => 'public'];
}

beforeEach(function (): void {
    config(['community.enabled' => true, 'community.handle_change_cooldown_days' => 30]);
    $this->city = testCity(['is_active' => true]);
    freezeLocal($this->city, '2026-10-01 15:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('lascia il primo cambio a chi non ne ha mai fatti e rifiuta il secondo sul campo, con la data', function (): void {
    $user = personaNomeUtente();
    $this->actingAs($user);

    $this->post(route('community.settings.update'), datiProfilo('nome_nuovo'))->assertRedirect()->assertSessionHasNoErrors();
    expect($user->communityProfile()->first()->handle_changed_at)->not->toBeNull();

    /* Il 1° ottobre alle 15 di Roma più trenta giorni esatti. Nel frattempo
       finisce l'ora legale, quindi l'ora mostrata è le 14: quella vera. */
    $this->post(route('community.settings.update'), datiProfilo('terzo_nome'))
        ->assertSessionHasErrors(['handle' => __('community.handle_cooldown', ['date' => '31 ottobre 2026 alle 14:00'])]);
    expect($user->communityProfile()->first()->handle)->toBe('nome_nuovo');
});

it('rifiuta il cambio anche dall API, nello stesso campo che l app mostra', function (): void {
    $user = personaNomeUtente();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/community/profile', datiProfilo('nome_nuovo'))->assertOk()
        ->assertJsonPath('data.handle_changeable_from', fn (?string $quando): bool => $quando !== null);

    $risposta = $this->postJson('/api/v1/community/profile', datiProfilo('terzo_nome'))->assertUnprocessable();
    expect($risposta->json('error.fields.handle.0'))->toContain('potrai cambiarlo di nuovo dal 31 ottobre 2026');
});

it('lascia salvare il resto del profilo con lo stesso nome durante l attesa', function (): void {
    $user = personaNomeUtente();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/community/profile', datiProfilo('nome_nuovo'))->assertOk();

    $this->postJson('/api/v1/community/profile', datiProfilo('nome_nuovo', 'Nome pubblico diverso'))->assertOk();
    expect($user->communityProfile()->first()->display_name)->toBe('Nome pubblico diverso');
});

it('riapre il cambio passati trenta giorni', function (): void {
    $user = personaNomeUtente();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/community/profile', datiProfilo('nome_nuovo'))->assertOk();

    $this->travel(29)->days();
    $this->postJson('/api/v1/community/profile', datiProfilo('terzo_nome'))->assertUnprocessable();

    $this->travel(1)->days();
    $this->postJson('/api/v1/community/profile', datiProfilo('terzo_nome'))->assertOk();
    expect($user->communityProfile()->first()->handle)->toBe('terzo_nome');
});

it('non conta come cambio il primo nome scelto', function (): void {
    $user = personaNomeUtente(null);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/community/profile', datiProfilo('primo_nome'))->assertOk();
    expect($user->communityProfile()->first()->handle_changed_at)->toBeNull();

    // Il primo cambio vero resta libero subito dopo.
    $this->postJson('/api/v1/community/profile', datiProfilo('nome_corretto'))->assertOk();
    $this->postJson('/api/v1/community/profile', datiProfilo('altro_ancora'))->assertUnprocessable();
});

it('dice dal vivo che il cambio non è ancora possibile, e da quando', function (): void {
    $user = personaNomeUtente();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/community/profile', datiProfilo('nome_nuovo'))->assertOk();
    // Ogni richiesta vera ricarica l'utente; nel test l'istanza di Sanctum è la stessa.
    Sanctum::actingAs($user->fresh());

    $this->getJson('/api/v1/community/profile/handle?handle=nome_libero')->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.reason', __('community.handle_cooldown', ['date' => '31 ottobre 2026 alle 14:00']))
        ->assertJsonPath('data.changeable_from', fn (?string $quando): bool => $quando !== null);

    // Il proprio nome attuale non è un cambio: resta «disponibile», senza attesa da dire.
    $this->getJson('/api/v1/community/profile/handle?handle=nome_nuovo')->assertOk()
        ->assertJsonPath('data.available', true)->assertJsonPath('data.changeable_from', null);
});

it('mostra l attesa nel modulo del sito', function (): void {
    $user = personaNomeUtente();
    $this->actingAs($user)->post(route('community.settings.update'), datiProfilo('nome_nuovo'))->assertSessionHasNoErrors();

    $this->actingAs($user->fresh())->get(route('community.settings'))->assertOk()
        ->assertSee('potrai cambiarlo di nuovo dal 31 ottobre 2026', false);
});

it('lascia alla redazione cambiare un nome senza attesa, e da lì riparte l attesa', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $moderatore = User::factory()->create();
    $moderatore->assignRole(UserRole::Moderator->value);
    $user = personaNomeUtente();
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/community/profile', datiProfilo('nome_offensivo'))->assertOk();

    $this->travel(2)->days();
    app(CommunityModeration::class)->renameHandle($moderatore, $user->communityProfile->id, 'nome_neutro', 'Nome offensivo segnalato');

    $profilo = $user->communityProfile()->first();
    expect($profilo->handle)->toBe('nome_neutro')
        ->and($profilo->handle_changed_at->equalTo(CarbonImmutable::now()))->toBeTrue();

    // Il titolare non torna indietro il minuto dopo.
    Sanctum::actingAs($user->fresh());
    $this->postJson('/api/v1/community/profile', datiProfilo('nome_offensivo'))->assertUnprocessable();
});

it('non lascia cambiare nomi a chi non è della redazione, né a un pari grado', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $user = personaNomeUtente();
    $qualunque = User::factory()->create();

    expect(fn () => app(CommunityModeration::class)->renameHandle($qualunque, $user->communityProfile->id, 'nome_rubato', 'Tentativo non autorizzato'))
        ->toThrow(HttpException::class);

    $moderatore = User::factory()->create();
    $moderatore->assignRole(UserRole::Moderator->value);
    $collega = personaNomeUtente('collega_mod');
    $collega->assignRole(UserRole::Moderator->value);

    expect(fn () => app(CommunityModeration::class)->renameHandle($moderatore, $collega->communityProfile->id, 'nome_rubato', 'Fra pari grado no'))
        ->toThrow(HttpException::class);
    expect($user->communityProfile()->first()->handle)->toBe('nome_iniziale')
        ->and($collega->communityProfile()->first()->handle)->toBe('collega_mod');
});

it('offre il cambio di nome nella tabella utenti della redazione', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $user = personaNomeUtente();
    $user->communityProfile->forceFill(['handle_changed_at' => now()])->save();

    $this->actingAs($admin);
    Livewire::test(ListUsers::class)
        ->callTableAction('community_rename_handle', $user, ['handle' => 'scelto_dalla_redazione', 'reason' => 'Nome che imita un locale'])
        ->assertHasNoTableActionErrors();

    expect($user->communityProfile()->first()->handle)->toBe('scelto_dalla_redazione');
});
