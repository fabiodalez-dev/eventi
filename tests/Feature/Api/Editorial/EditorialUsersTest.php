<?php

declare(strict_types=1);

use App\Enums\ProfileVisibility;
use App\Enums\UserRole;
use App\Enums\WhatsappChallengeStatus;
use App\Models\Device;
use App\Models\User;
use App\Models\WhatsappChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R5 — Utenti nella redazione dell'app (R-US-01, 02, 04…06, 08…12), con la
 * griglia dei ruoli: moderatore, admin, super admin, referente di un locale,
 * utente comune, token non sbloccato, password non recente sulle azioni
 * pericolose. Le regole sono quelle del pannello: `UserPolicy`,
 * `SyncUserRoles` (ex `RoleField`), `UpdateUserByStaff` (ex `EditUser`),
 * `CommunityModeration`, `WhatsappVerification`; in più i limiti della
 * decisione 4 (ruoli fino a moderatore, credenziali solo le proprie).
 */
beforeEach(function (): void {
    Notification::fake();
    config(['community.phone_hash_key' => 'test-fingerprint-key']);
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
});

function r5As(mixed $test, User $user, bool $unlocked = true, int $passwordMinutesAgo = 0): mixed
{
    return EditorialSession::as($test, EditorialSession::token($user, unlocked: $unlocked, passwordMinutesAgo: $passwordMinutesAgo));
}

function r5Log(string $description, User $subject): ?Activity
{
    return Activity::query()->where('log_name', 'sicurezza')->where('description', $description)->where('subject_id', $subject->id)->latest('id')->first();
}

it('apre l’elenco a tutta la redazione e lo chiude a referenti, utenti e token bloccati', function (): void {
    foreach ([$this->s->ownerA, $this->s->plainUser] as $outsider) {
        r5As($this, $outsider)->getJson('/api/v1/editorial/users')->assertNotFound();
    }
    r5As($this, $this->s->moderator, unlocked: false)->getJson('/api/v1/editorial/users')
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');

    foreach ([$this->s->moderator, $this->s->admin, $this->superAdmin] as $staff) {
        r5As($this, $staff)->getJson('/api/v1/editorial/users')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'registration' => ['value', 'label'], 'roles', 'memberships', 'city', 'created_at', 'abilities' => ['update', 'roles', 'destroy', 'restore', 'suspend', 'unsuspend', 'handle', 'whatsapp_revoke', 'whatsapp_history']]], 'meta' => ['current_page', 'last_page', 'per_page', 'total', 'tabs' => ['all', 'pending', 'admins', 'staff', 'owners', 'editors', 'users', 'unlinked']]]);
    }
});

it('dà a ogni riga le azioni del pannello per chi guarda', function (): void {
    $row = function (User $staff, User $target): array {
        $rows = r5As($this, $staff)->getJson('/api/v1/editorial/users?per_page=50')->assertOk()->json('data');

        return collect($rows)->firstWhere('id', $target->id)['abilities'];
    };

    // Il moderatore vede e guarda lo storico: non modifica, non elimina, non revoca e, senza `users.manage`, non sospende.
    expect($row($this->s->moderator, $this->s->plainUser))->toMatchArray(['update' => false, 'roles' => false, 'destroy' => false, 'suspend' => false, 'whatsapp_history' => true, 'whatsapp_revoke' => false])
        ->and($row($this->s->admin, $this->s->plainUser)['suspend'])->toBeTrue()
        // …e non sospende un pari grado né un admin.
        ->and($row($this->s->moderator, $this->s->admin)['suspend'])->toBeFalse()
        // L'admin modifica e elimina chi non è super admin, ma non se stesso (eliminare) né un super admin.
        ->and($row($this->s->admin, $this->s->plainUser))->toMatchArray(['update' => true, 'roles' => true, 'destroy' => true, 'credentials' => false])
        ->and($row($this->s->admin, $this->s->admin))->toMatchArray(['update' => true, 'destroy' => false, 'credentials' => true])
        ->and($row($this->s->admin, $this->superAdmin))->toMatchArray(['update' => false, 'destroy' => false, 'suspend' => false])
        // Il super admin arriva ovunque, tranne eliminare se stesso.
        ->and($row($this->superAdmin, $this->s->admin))->toMatchArray(['update' => true, 'destroy' => true, 'suspend' => true])
        ->and($row($this->superAdmin, $this->superAdmin)['destroy'])->toBeFalse();
});

it('ha le otto schede e i filtri del pannello, la ricerca e il tetto di 50 righe', function (): void {
    $ids = function (string $query): array {
        return collect(r5As($this, $this->s->admin)->getJson('/api/v1/editorial/users?per_page=50&'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    };
    $unlinked = EditorialSession::user(UserRole::VenueOwner);
    $pending = User::factory()->unverified()->create();
    $trashed = User::factory()->create();
    $trashed->delete();

    expect($ids('tab=staff'))->toBe([$this->s->moderator->id])
        ->and($ids('tab=admins'))->toBe(collect([$this->s->admin->id, $this->superAdmin->id])->sort()->values()->all())
        ->and($ids('tab=owners'))->toBe(collect([$this->s->ownerA->id, $this->s->ownerB->id])->sort()->values()->all())
        ->and($ids('tab=editors'))->toBe([$this->s->editorA->id])
        ->and($ids('tab=unlinked'))->toBe([$unlinked->id])
        ->and($ids('tab=pending'))->toContain($pending->id)
        ->and($ids('filter[role]=moderator'))->toBe([$this->s->moderator->id])
        ->and($ids('filter[venue]='.$this->s->venueA->id))->toBe(collect([$this->s->ownerA->id, $this->s->editorA->id])->sort()->values()->all())
        ->and($ids('filter[registration]=pending'))->toContain($pending->id)
        ->and($ids('filter[trashed]=only'))->toBe([$trashed->id])
        ->and($ids(''))->not->toContain($trashed->id)
        ->and($ids('q=Teatro Belzoni'))->toBe([$this->s->ownerB->id]);

    r5As($this, $this->s->admin)->getJson('/api/v1/editorial/users?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 50);
    r5As($this, $this->s->admin)->getJson('/api/v1/editorial/users?tab=nessuna')->assertUnprocessable();
    r5As($this, $this->s->admin)->getJson('/api/v1/editorial/users?filter[inventato]=1')->assertUnprocessable();
});

it('non fa una query per riga', function (): void {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        r5As($this, $this->s->admin)->getJson('/api/v1/editorial/users?per_page=50')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    // La prima richiesta scalda le cache dei permessi: si conta dalla seconda.
    $count();
    $few = $count();
    foreach (range(1, 8) as $i) {
        $user = User::factory()->create();
        $user->assignRole(UserRole::VenueOwner->value);
        $user->venues()->attach($this->s->venueA, ['role' => 'owner', 'invited_at' => now(), 'accepted_at' => now()]);
        $user->communityProfile()->create(['handle' => 'persona_'.$i, 'display_name' => 'Persona '.$i, 'visibility' => ProfileVisibility::Members]);
    }

    expect($count())->toBe($few);
});

it('dà le scelte dei moduli e i ruoli che dall’app si possono dare', function (): void {
    r5As($this, $this->s->moderator)->getJson('/api/v1/editorial/users/options')->assertOk();
    $admin = r5As($this, $this->s->admin)->getJson('/api/v1/editorial/users/options')->assertOk()->json('data');
    $super = r5As($this, $this->superAdmin)->getJson('/api/v1/editorial/users/options')->assertOk()->json('data');

    expect($admin['assignable_roles'])->toBe(['user', 'venue_owner', 'venue_editor', 'moderator'])
        ->and($super['assignable_roles'])->toBe(['user', 'venue_owner', 'venue_editor', 'moderator'])
        ->and(collect($admin['registration_states'])->pluck('value')->all())->toBe(['confirmed', 'pending', 'first_access'])
        ->and($admin['timezones'])->toContain('Europe/Rome')
        ->and($admin['locales'])->toBe(['it', 'en', 'de']);
});

it('crea un utente con i ruoli fino a moderatore, e lo scrive nel registro', function (): void {
    $body = ['name' => 'Nuova Moderatrice', 'email' => 'nuova@example.test', 'password' => 'una-password-lunga', 'city_id' => $this->s->city->id, 'timezone' => 'Europe/Rome', 'locale' => 'it', 'roles' => ['moderator']];

    r5As($this, $this->s->moderator)->postJson('/api/v1/editorial/users', $body)->assertForbidden();
    r5As($this, $this->s->admin)->postJson('/api/v1/editorial/users', [...$body, 'roles' => ['admin']])
        ->assertUnprocessable()->assertJsonPath('error.fields', fn (array $fields): bool => array_key_exists('roles.0', $fields));

    $id = r5As($this, $this->s->admin)->postJson('/api/v1/editorial/users', $body)->assertCreated()
        ->assertJsonPath('data.form.roles', ['moderator'])
        ->assertJsonPath('data.registration.value', 'pending')
        ->json('data.id');

    $user = User::query()->findOrFail($id);
    expect($user->hasRole('moderator'))->toBeTrue()
        ->and(Hash::check('una-password-lunga', (string) $user->password))->toBeTrue()
        ->and(r5Log('ruoli_cambiati', $user)?->getProperty('canale'))->toBe('app')
        ->and(r5Log('ruoli_cambiati', $user)?->causer_id)->toBe($this->s->admin->id);

    r5As($this, $this->s->admin)->postJson('/api/v1/editorial/users', $body)->assertUnprocessable()->assertJsonPath('error.fields', fn (array $fields): bool => array_key_exists('email', $fields));
});

it('modifica città, nome, fuso e lingua e rifiuta email e password altrui', function (): void {
    $url = '/api/v1/editorial/users/'.$this->s->plainUser->id;

    r5As($this, $this->s->moderator)->patchJson($url, ['name' => 'Altro'])->assertForbidden();
    r5As($this, $this->s->admin)->patchJson($url, ['name' => 'Nome corretto', 'timezone' => 'Europe/London', 'locale' => 'en', 'city_id' => $this->s->city->id])
        ->assertOk()->assertJsonPath('data.form.name', 'Nome corretto')->assertJsonPath('data.form.timezone', 'Europe/London')->assertJsonPath('data.city.id', $this->s->city->id);
    r5As($this, $this->s->admin)->patchJson($url, ['email' => 'presa@example.test'])->assertUnprocessable();
    r5As($this, $this->s->admin)->patchJson($url, ['password' => 'una-password-nuova'])->assertUnprocessable();
    r5As($this, $this->s->admin)->patchJson($url, ['timezone' => 'Marte/Base'])->assertUnprocessable();
    // Un super admin si modifica solo da un super admin.
    r5As($this, $this->s->admin)->patchJson('/api/v1/editorial/users/'.$this->superAdmin->id, ['name' => 'X'])->assertForbidden();
    r5As($this, $this->superAdmin)->patchJson('/api/v1/editorial/users/'.$this->s->admin->id, ['name' => 'Admin rinominato'])->assertOk();
});

it('non lascia conferire un ruolo più alto del proprio né admin dall’app, e conserva quelli che non si possono toccare', function (): void {
    $url = fn (User $user): string => '/api/v1/editorial/users/'.$user->id.'/roles';

    r5As($this, $this->s->moderator)->putJson($url($this->s->plainUser), ['roles' => ['moderator']])->assertForbidden();
    r5As($this, $this->s->admin)->putJson($url($this->s->plainUser), ['roles' => ['super_admin']])->assertUnprocessable();
    r5As($this, $this->s->admin)->putJson($url($this->s->plainUser), ['roles' => ['admin']])->assertUnprocessable();
    r5As($this, $this->superAdmin)->putJson($url($this->s->plainUser), ['roles' => ['super_admin']])->assertUnprocessable();
    // Nemmeno su se stesso.
    r5As($this, $this->s->admin)->putJson($url($this->s->admin), ['roles' => ['super_admin']])->assertUnprocessable();
    expect($this->s->admin->fresh()->hasRole('super_admin'))->toBeFalse();

    r5As($this, $this->s->admin)->putJson($url($this->s->plainUser), ['roles' => ['moderator']])->assertOk()->assertJsonPath('data.form.roles', ['moderator']);
    $log = r5Log('ruoli_cambiati', $this->s->plainUser);
    expect($log?->getProperty('prima'))->toBe(['user'])
        ->and($log?->getProperty('dopo'))->toBe(['moderator'])
        ->and($log?->getProperty('canale'))->toBe('app');

    // L'amministrazione che la persona ha già resta: si toglie dal sito.
    $otherAdmin = EditorialSession::user(UserRole::Admin);
    r5As($this, $this->s->admin)->putJson($url($otherAdmin), ['roles' => ['moderator']])->assertOk()->assertJsonPath('data.form.roles', ['admin', 'moderator']);
    // Un salvataggio che non cambia niente non va nel registro.
    $before = Activity::query()->where('description', 'ruoli_cambiati')->count();
    r5As($this, $this->s->admin)->putJson($url($this->s->plainUser), ['roles' => ['moderator']])->assertOk();
    expect(Activity::query()->where('description', 'ruoli_cambiati')->count())->toBe($before);
});

it('chiede la password recente per ruoli, eliminazione, sospensione e credenziali', function (): void {
    $id = $this->s->plainUser->id;
    $calls = [
        ['putJson', "/api/v1/editorial/users/{$id}/roles", ['roles' => ['moderator']]],
        ['deleteJson', "/api/v1/editorial/users/{$id}", []],
        ['postJson', "/api/v1/editorial/users/{$id}/community/suspend", []],
        ['putJson', '/api/v1/editorial/users/'.$this->s->admin->id.'/credentials', ['email' => 'nuova@example.test']],
    ];
    foreach ($calls as [$method, $url, $body]) {
        r5As($this, $this->s->admin, passwordMinutesAgo: 16)->{$method}($url, $body)
            ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    }
    expect($this->s->plainUser->fresh()->trashed())->toBeFalse();
});

it('cambia le proprie credenziali e revoca token e dispositivi, mai quelle altrui', function (): void {
    r5As($this, $this->s->admin)->putJson('/api/v1/editorial/users/'.$this->s->plainUser->id.'/credentials', ['email' => 'presa@example.test'])->assertForbidden();
    r5As($this, $this->s->moderator)->putJson('/api/v1/editorial/users/'.$this->s->moderator->id.'/credentials', ['email' => 'mia@example.test'])->assertForbidden();

    $this->s->admin->createToken('telefono-vecchio');
    $device = Device::factory()->for($this->s->admin)->create();

    r5As($this, $this->s->admin)->putJson('/api/v1/editorial/users/'.$this->s->admin->id.'/credentials', ['email' => 'admin-nuovo@example.test', 'password' => 'password-tutta-nuova'])
        ->assertOk()->assertJsonPath('meta.signed_out', true);

    $admin = $this->s->admin->fresh();
    expect($admin->email)->toBe('admin-nuovo@example.test')
        ->and(Hash::check('password-tutta-nuova', (string) $admin->password))->toBeTrue()
        ->and($admin->tokens()->count())->toBe(0)
        ->and($device->fresh()->revoked_at)->not->toBeNull()
        ->and(r5Log('email_cambiata_da_staff', $admin)?->getProperty('canale'))->toBe('app')
        ->and(r5Log('password_impostata_da_staff', $admin))->not->toBeNull();
});

it('elimina nel cestino e ripristina come la policy, e lo scrive nel registro', function (): void {
    $url = '/api/v1/editorial/users/'.$this->s->plainUser->id;

    r5As($this, $this->s->moderator)->deleteJson($url)->assertForbidden();
    r5As($this, $this->s->admin)->deleteJson('/api/v1/editorial/users/'.$this->s->admin->id)->assertForbidden();
    r5As($this, $this->s->admin)->deleteJson('/api/v1/editorial/users/'.$this->superAdmin->id)->assertForbidden();

    r5As($this, $this->s->admin)->deleteJson($url)->assertOk()->assertJsonPath('data.abilities.restore', true)->assertJsonPath('data.abilities.destroy', false);
    expect($this->s->plainUser->fresh()->trashed())->toBeTrue()
        ->and(r5Log('utente_eliminato', $this->s->plainUser)?->getProperty('canale'))->toBe('app')
        ->and(r5Log('utente_eliminato', $this->s->plainUser)?->getProperty('definitivo'))->toBeFalse();

    // La scheda di un account nel cestino si apre ancora.
    r5As($this, $this->s->moderator)->getJson($url)->assertOk()->assertJsonPath('data.deleted_at', fn (?string $value): bool => $value !== null);
    r5As($this, $this->s->moderator)->postJson($url.'/restore')->assertForbidden();
    r5As($this, $this->s->admin)->postJson($url.'/restore')->assertOk();
    expect($this->s->plainUser->fresh()->trashed())->toBeFalse()
        ->and(r5Log('utente_ripristinato', $this->s->plainUser))->not->toBeNull();
});

it('sospende e riattiva dalla community con users.manage, solo chi ha rango più basso', function (): void {
    $suspend = fn (User $target): string => '/api/v1/editorial/users/'.$target->id.'/community/suspend';
    $otherAdmin = EditorialSession::user(UserRole::Admin);

    // La regola unica della sospensione da tutta la community: il moderatore non ha `users.manage`.
    r5As($this, $this->s->moderator)->postJson($suspend($this->s->plainUser))->assertForbidden();
    r5As($this, $this->s->admin)->postJson($suspend($this->superAdmin))->assertForbidden();
    r5As($this, $this->s->admin)->postJson($suspend($otherAdmin))->assertForbidden();
    r5As($this, $this->s->admin)->postJson($suspend($this->s->plainUser))->assertOk()
        ->assertJsonPath('data.abilities.unsuspend', true)->assertJsonPath('data.abilities.suspend', false);
    expect($this->s->plainUser->fresh()->community_suspended_at)->not->toBeNull()
        ->and(Activity::query()->where('log_name', 'community')->where('event', 'moderation')->latest('id')->first()?->getProperty('canale'))->toBe('app');

    // Riattivare segue la stessa regola: l'admin sospeso dal super admin non lo riattiva né il moderatore né un altro admin.
    r5As($this, $this->superAdmin)->postJson($suspend($this->s->admin))->assertOk();
    r5As($this, $this->s->moderator)->postJson('/api/v1/editorial/users/'.$this->s->admin->id.'/community/unsuspend')->assertForbidden();
    r5As($this, $otherAdmin)->postJson('/api/v1/editorial/users/'.$this->s->admin->id.'/community/unsuspend')->assertForbidden();
    expect($this->s->admin->fresh()->community_suspended_at)->not->toBeNull();
    r5As($this, $this->superAdmin, passwordMinutesAgo: 16)->postJson('/api/v1/editorial/users/'.$this->s->admin->id.'/community/unsuspend')->assertOk();
    expect($this->s->admin->fresh()->community_suspended_at)->toBeNull();
    r5As($this, $this->s->ownerA)->postJson($suspend($this->s->plainUser))->assertNotFound();
});

it('cambia il nome utente con il motivo, solo verso il basso', function (): void {
    config(['community.enabled' => true]);
    $this->s->plainUser->communityProfile()->create(['handle' => 'nome_offensivo', 'display_name' => 'Persona', 'visibility' => ProfileVisibility::Members]);
    $this->s->admin->communityProfile()->create(['handle' => 'admin_nome', 'display_name' => 'Admin', 'visibility' => ProfileVisibility::Members]);
    $url = fn (User $user): string => '/api/v1/editorial/users/'.$user->id.'/community/handle';

    r5As($this, $this->s->moderator)->postJson($url($this->s->plainUser), ['handle' => 'nome_neutro', 'reason' => 'no'])->assertUnprocessable();
    r5As($this, $this->s->moderator)->postJson($url($this->s->admin), ['handle' => 'nome_neutro', 'reason' => 'Nome che imita un locale'])->assertForbidden();
    r5As($this, $this->s->moderator)->postJson($url($this->s->plainUser), ['handle' => 'nome_neutro', 'reason' => 'Nome offensivo segnalato'])
        ->assertOk()->assertJsonPath('data.community_handle', 'nome_neutro');
    r5As($this, $this->s->moderator)->postJson($url($this->s->moderator), ['handle' => 'x', 'reason' => 'Senza profilo'])->assertNotFound();
});

it('revoca la verifica WhatsApp solo da admin e mostra lo storico a tutta la redazione', function (): void {
    $phone = '+393331234567';
    $this->s->plainUser->forceFill(['whatsapp_phone' => $phone, 'whatsapp_phone_hash' => 'impronta', 'whatsapp_verified_at' => now()])->save();
    WhatsappChallenge::query()->create([
        'id' => (string) Str::uuid(), 'user_id' => $this->s->plainUser->id, 'phone' => $phone, 'phone_hash' => 'impronta',
        'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5), 'status' => WhatsappChallengeStatus::Sent,
    ]);
    $base = '/api/v1/editorial/users/'.$this->s->plainUser->id;

    r5As($this, $this->s->moderator)->getJson($base.'/whatsapp/history')->assertOk()
        ->assertJsonPath('data.0.phone_masked', '••••4567')->assertJsonPath('data.0.status', 'sent')
        ->assertJsonMissing(['phone' => $phone]);
    r5As($this, $this->s->moderator)->getJson($base)->assertOk()->assertJsonPath('data.whatsapp_masked', '••••4567');
    r5As($this, $this->s->moderator)->deleteJson($base.'/whatsapp')->assertForbidden();
    r5As($this, $this->s->admin)->deleteJson($base.'/whatsapp')->assertOk()->assertJsonPath('data.whatsapp_verified_at', null);
    expect($this->s->plainUser->fresh()->whatsapp_verified_at)->toBeNull();
    r5As($this, $this->s->admin)->deleteJson($base.'/whatsapp')->assertNotFound();
});
