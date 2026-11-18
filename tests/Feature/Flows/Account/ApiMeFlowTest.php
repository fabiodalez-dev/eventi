<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Models\Device;
use App\Models\Follow;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * L'area personale dell'app (`/api/v1/me`, §15.8, §15.9): profilo, sessioni,
 * dispositivi, esportazione e cancellazione. Il token si ottiene come lo
 * ottiene l'app, con l'accesso, e fra due identità si dimentica la guardia:
 * nella suite il container sopravvive fra una richiesta e l'altra.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Accede dall'app e restituisce il token in chiaro. */
function flowAccountMeLogin(string $email, string $password, ?string $device = null): string
{
    flowAccountMeReset();

    return (string) test()->postJson('/api/v1/auth/login', array_filter([
        'email' => $email,
        'password' => $password,
        'device_name' => $device,
    ]))->assertOk()->json('data.token');
}

function flowAccountMeReset(): void
{
    test()->flushHeaders();
    app('auth')->forgetGuards();
}

it('legge e aggiorna il profilo dall app, ignorando i campi che non si possono cambiare', function (): void {
    $user = User::factory()->create(['name' => 'Sara', 'email' => 'sara.me@example.test', 'password' => Hash::make('password-sara-me')]);

    $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

    $token = flowAccountMeLogin('sara.me@example.test', 'password-sara-me');

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.name', 'Sara')
        ->assertJsonPath('data.email', 'sara.me@example.test')
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('data.marketing_opt_in', false);

    $this->withToken($token)->patchJson('/api/v1/me', [
        'name' => 'Sara Blu',
        'email' => 'cambiata.me@example.test',
        'timezone' => 'Europe/Berlin',
        'daily_digest_time' => '07:30',
        'quiet_hours' => ['from' => '22:00', 'to' => '06:30'],
        'marketing_opt_in' => true,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Sara Blu')
        ->assertJsonPath('data.email', 'sara.me@example.test')
        ->assertJsonPath('data.timezone', 'Europe/Berlin')
        ->assertJsonPath('data.marketing_opt_in', true);

    $user->refresh();
    $consenso = $user->marketing_opt_in_at;
    expect($user->email)->toBe('sara.me@example.test')
        ->and($user->quiet_hours)->toBe(['from' => '22:00', 'to' => '06:30'])
        ->and((string) $user->daily_digest_time)->toStartWith('07:30')
        ->and($consenso)->not->toBeNull();

    /* Riconfermare il consenso non ne riscrive la data; un orario fuori
       scala e una lingua non tradotta sono errori, e non cambiano niente. */
    $this->travel(5)->minutes();
    $this->withToken($token)->patchJson('/api/v1/me', ['marketing_opt_in' => true])->assertOk();
    expect($user->fresh()->marketing_opt_in_at?->equalTo($consenso))->toBeTrue();

    $this->withToken($token)->patchJson('/api/v1/me', ['quiet_hours' => ['from' => '25:00', 'to' => '06:00'], 'locale' => 'en'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['quiet_hours.from', 'locale']]]);
    expect($user->fresh()->quiet_hours)->toBe(['from' => '22:00', 'to' => '06:30']);

    /* Revocare il consenso cancella la data; un oggetto vuoto è «nessun
       silenzio» scelto di proposito. */
    $this->withToken($token)->patchJson('/api/v1/me', ['marketing_opt_in' => false, 'quiet_hours' => []])
        ->assertOk()
        ->assertJsonPath('data.marketing_opt_in', false);
    expect($user->fresh()->marketing_opt_in_at)->toBeNull()
        ->and($user->fresh()->quiet_hours)->toBe([]);
});

it('elenca le sessioni aperte, chiude quella del tablet revocandone il dispositivo e non tocca quelle altrui', function (): void {
    $user = User::factory()->create(['email' => 'sessioni.me@example.test', 'password' => Hash::make('password-sessioni')]);
    $altro = User::factory()->create();
    $sessioneAltrui = $altro->createToken('Telefono di un altro');

    $telefono = flowAccountMeLogin('sessioni.me@example.test', 'password-sessioni', 'Telefono');
    $tablet = flowAccountMeLogin('sessioni.me@example.test', 'password-sessioni', 'Tablet');

    /* Il tablet registra il proprio dispositivo push: il token ne porta il
       riferimento. */
    flowAccountMeReset();
    $dispositivo = (int) $this->withToken($tablet)->postJson('/api/v1/me/devices', [
        'platform' => 'android',
        'installation_id' => 'installazione-tablet-0001',
        'push_token' => 'fcm-token-del-tablet',
    ])->assertCreated()->json('data.id');

    $tokenTablet = PersonalAccessToken::findToken($tablet);
    expect((int) $tokenTablet?->getAttribute('device_id'))->toBe($dispositivo);

    flowAccountMeReset();
    $elenco = $this->withToken($telefono)->getJson('/api/v1/me/sessions')->assertOk()->assertJsonCount(2, 'data');

    $sessioni = collect($elenco->json('data'));
    expect($sessioni->firstWhere('current', true)['name'] ?? null)->toBe('Telefono')
        ->and($sessioni->firstWhere('name', 'Tablet')['current'] ?? null)->toBeFalse()
        ->and($sessioni->firstWhere('name', 'Tablet')['device_id'] ?? null)->toBe($dispositivo)
        ->and($elenco->json())->not->toHaveKey('data.0.token');

    /* La sessione di un altro non esiste, per questo account. */
    $this->withToken($telefono)->deleteJson('/api/v1/me/sessions/'.$sessioneAltrui->accessToken->getKey())
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
    expect($altro->tokens()->count())->toBe(1);

    $this->withToken($telefono)->deleteJson('/api/v1/me/sessions/'.$tokenTablet?->getKey())
        ->assertOk()
        ->assertJsonPath('data.message', __('account.api.session_revoked'));

    expect($user->tokens()->pluck('name')->all())->toBe(['Telefono'])
        ->and(Device::query()->find($dispositivo)?->revoked_at)->not->toBeNull();

    flowAccountMeReset();
    $this->withToken($tablet)->getJson('/api/v1/me')->assertUnauthorized();

    flowAccountMeReset();
    $this->withToken($telefono)->getJson('/api/v1/me/sessions')->assertOk()->assertJsonCount(1, 'data');
});

it('registra il telefono per le notifiche, lo aggiorna al cambio di token push, lo revoca e non tocca quelli altrui', function (): void {
    $user = User::factory()->create(['email' => 'dispositivi.me@example.test', 'password' => Hash::make('password-dispositivi')]);
    $altrui = Device::factory()->create();

    $token = flowAccountMeLogin('dispositivi.me@example.test', 'password-dispositivi', 'Telefono');

    $this->withToken($token)->getJson('/api/v1/me/devices')->assertOk()->assertJsonCount(0, 'data');

    /* Un telefono Android senza identificativo d'installazione non passa. */
    $this->withToken($token)->postJson('/api/v1/me/devices', ['platform' => 'android', 'push_token' => 'fcm-senza-installazione'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['installation_id']]]);

    $primo = $this->withToken($token)->postJson('/api/v1/me/devices', [
        'platform' => 'android',
        'installation_id' => 'installazione-telefono-01',
        'push_token' => 'fcm-primo-token',
        'app_version' => '1.22.2',
    ])->assertCreated()
        ->assertJsonPath('data.platform', 'android')
        ->assertJsonPath('data.app_version', '1.22.2');

    /* Il token push non esce mai dalla risposta. */
    expect($primo->json('data'))->not->toHaveKey('push_token')->not->toHaveKey('endpoint');
    $id = (int) $primo->json('data.id');

    /* Stessa installazione, token push ruotato: la riga è la stessa. */
    $this->withToken($token)->postJson('/api/v1/me/devices', [
        'platform' => 'android',
        'installation_id' => 'installazione-telefono-01',
        'push_token' => 'fcm-token-ruotato',
        'app_version' => '1.23.0',
    ])->assertCreated()->assertJsonPath('data.id', $id);

    $riga = Device::query()->findOrFail($id);
    expect($user->devices()->count())->toBe(1)
        ->and($riga->app_version)->toBe('1.23.0')
        ->and($riga->token_hash)->toBe(hash('sha256', 'fcm-token-ruotato'))
        ->and($riga->revoked_at)->toBeNull();

    $this->withToken($token)->getJson('/api/v1/me/devices')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.revoked_at', null);

    $this->withToken($token)->deleteJson('/api/v1/me/devices/'.$altrui->getKey())->assertNotFound();
    expect($altrui->fresh()->revoked_at)->toBeNull();

    $this->withToken($token)->deleteJson('/api/v1/me/devices/'.$id)
        ->assertOk()
        ->assertJsonPath('data.message', __('account.api.device_revoked'));

    /* Revocato, non cancellato: l'invio ripiega sull'email. */
    expect($riga->fresh()->revoked_at)->not->toBeNull();
    $this->withToken($token)->getJson('/api/v1/me/devices')->assertOk()->assertJsonPath('data.0.id', $id);
    expect($this->withToken($token)->getJson('/api/v1/me/devices')->json('data.0.revoked_at'))->not->toBeNull();
});

it('esporta i propri dati dall app e poi cancella l account con conferma e password, spegnendo il token', function (): void {
    freezeLocal($this->city, '2026-09-10 18:00');
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: ['title' => 'Serata esportata']);

    $user = User::factory()->create(['email' => 'via.me@example.test', 'name' => 'Chi va', 'password' => Hash::make('password-via-me')]);
    app(SaveOccurrences::class)->one($user, $occurrence);
    Follow::factory()->create(['user_id' => $user->getKey()]);

    $token = flowAccountMeLogin('via.me@example.test', 'password-via-me', 'Telefono');

    $export = $this->withToken($token)->getJson('/api/v1/me/export')->assertOk();

    expect($export->json('data.profile.email'))->toBe('via.me@example.test')
        ->and($export->json('data.saved_events'))->toHaveCount(1)
        ->and($export->json('data.saved_events.0.event_title'))->toBe('Serata esportata')
        ->and($export->json('data.follows'))->toHaveCount(1)
        ->and((string) json_encode($export->json('data')))->not->toContain('$2y$')
        ->and((string) json_encode($export->json('data')))->not->toContain(explode('|', $token)[1]);

    $this->withToken($token)->deleteJson('/api/v1/me', ['confirmation' => __('account.profile.delete_keyword'), 'password' => 'non-e-lei'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    $this->withToken($token)->deleteJson('/api/v1/me', ['confirmation' => 'forse', 'password' => 'password-via-me'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['confirmation']]]);
    expect($user->fresh()->trashed())->toBeFalse();

    $this->withToken($token)->deleteJson('/api/v1/me', ['confirmation' => __('account.profile.delete_keyword'), 'password' => 'password-via-me'])
        ->assertOk()
        ->assertJsonPath('data.message', __('account.api.deleted'));

    $user->refresh();
    expect($user->trashed())->toBeTrue()
        ->and($user->name)->toBeNull()
        ->and($user->email)->toEndWith('.invalid')
        ->and($user->savedEvents()->count())->toBe(0)
        ->and($user->follows()->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0);

    flowAccountMeReset();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

    flowAccountMeReset();
    $this->postJson('/api/v1/auth/login', ['email' => 'via.me@example.test', 'password' => 'password-via-me'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});
