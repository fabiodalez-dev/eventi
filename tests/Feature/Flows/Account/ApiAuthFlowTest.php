<?php

declare(strict_types=1);

use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Notifications\AccountAlreadyRegistered;
use App\Notifications\MagicLoginLink;
use App\Notifications\RegistrationLink;
use App\Notifications\ResetPasswordLink;
use App\Notifications\VerifyEmailLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * L'autenticazione dell'app (`/api/v1/auth/*`, §15.2, D64): registrazione con
 * PKCE, scambio del collegamento, accesso con password, collegamento senza
 * password, conferma dell'email e reimpostazione della password. Ogni flusso
 * passa da un messaggio spedito davvero e dal token che ne esce.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Notification::fake();
});

/** La sfida PKCE S256 di un verificatore, come la calcola l'app. */
function flowAccountApiChallenge(string $verificatore): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verificatore, true)), '+/', '-_'), '=');
}

/** Il token opaco dentro un collegamento `/app/auth/magic?token=…`. */
function flowAccountApiLinkToken(string $url): string
{
    expect($url)->toContain('/app/auth/magic?token=');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

/**
 * Cambia identità fra due richieste: niente intestazioni della precedente e
 * nessuna guardia che ricordi l'utente già riconosciuto (nella suite il
 * container sopravvive fra una richiesta e l'altra).
 */
function flowAccountApiReset(): void
{
    test()->flushHeaders();
    app('auth')->forgetGuards();
}

it('si registra dall app, scambia il collegamento solo con il proprio verificatore e usa il token fino all uscita', function (): void {
    $verificatore = str_repeat('v', 43);

    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Luca',
        'last_name' => 'Neri',
        'email' => 'luca.app@example.test',
        'password' => 'password-dell-app',
        'password_confirmation' => 'password-dell-app',
        'device_name' => 'Pixel di Luca',
        'code_challenge' => flowAccountApiChallenge($verificatore),
    ])->assertStatus(202)
        ->assertJsonPath('data.message', __('api.auth.registration_sent'))
        ->assertJsonMissingPath('data.token');

    $user = User::query()->where('email', 'luca.app@example.test')->firstOrFail();
    expect($user->name)->toBe('Luca Neri')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->email_claim_pending_at)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(0);

    $url = Notification::sent($user, RegistrationLink::class)->first()->url;
    $token = flowAccountApiLinkToken($url);

    /* Nel browser il collegamento apre la pagina che rimanda all'app, e non
       resta in nessuna cache. */
    $pagina = $this->get($url)->assertOk();
    expect((string) $pagina->headers->get('Cache-Control'))->toContain('no-store');

    /* Il verificatore di un'altra installazione non scambia niente, e non
       brucia il collegamento. */
    $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => $token, 'code_verifier' => str_repeat('x', 43)])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');
    expect($user->tokens()->count())->toBe(0);

    $scambio = $this->postJson('/api/v1/auth/magic-link/exchange', [
        'token' => $token,
        'code_verifier' => $verificatore,
        'device_name' => 'Pixel di Luca',
    ])->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'luca.app@example.test')
        ->assertJsonPath('data.user.email_verified', true);

    $bearer = (string) $scambio->json('data.token');
    $user->refresh();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->email_claim_pending_at)->toBeNull()
        /* Lo scambio viene dall'installazione che ha scelto la password. */
        ->and(Hash::check('password-dell-app', (string) $user->password))->toBeTrue()
        ->and($user->tokens()->pluck('name')->all())->toBe(['Pixel di Luca']);

    /* Un collegamento si scambia una volta sola. */
    $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => $token, 'code_verifier' => $verificatore])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');

    $this->withToken($bearer)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'luca.app@example.test');
    $this->withToken($bearer)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data.message', __('api.auth.logged_out'));
    expect($user->tokens()->count())->toBe(0);

    app('auth')->forgetGuards();
    $this->withToken($bearer)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

    /* Confermata la casella, la password scelta in registrazione entra. */
    flowAccountApiReset();
    $this->postJson('/api/v1/auth/login', ['email' => 'luca.app@example.test', 'password' => 'password-dell-app'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'expires_at', 'user']]);
});

it('non rivela dall app un indirizzo già registrato e tiene in sospeso la password finché il collegamento rispedito non è scambiato', function (): void {
    $esistente = User::factory()->create(['email' => 'gia.app@example.test', 'name' => 'Titolare']);
    $hash = $esistente->password;
    $primo = str_repeat('a', 43);
    $secondo = str_repeat('b', 43);

    $nuova = $this->postJson('/api/v1/auth/register', [
        'email' => 'nuova.app@example.test',
        'password' => 'password-nuova-app',
        'code_challenge' => flowAccountApiChallenge($primo),
    ])->assertStatus(202);
    $gia = $this->postJson('/api/v1/auth/register', [
        'name' => 'Intruso',
        'email' => 'gia.app@example.test',
        'password' => 'password-intrusa-app',
    ])->assertStatus(202);

    expect($gia->json())->toBe($nuova->json())
        ->and($esistente->fresh()->password)->toBe($hash)
        ->and($esistente->fresh()->name)->toBe('Titolare');
    Notification::assertSentTo($esistente, AccountAlreadyRegistered::class);
    Notification::assertNotSentTo($esistente, RegistrationLink::class);

    /* Gli errori dei campi restano errori: una password debole non passa. */
    $this->postJson('/api/v1/auth/register', ['email' => 'debole.app@example.test', 'password' => 'corta'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['password']]]);
    expect(User::query()->where('email', 'debole.app@example.test')->exists())->toBeFalse();

    $nuovo = User::query()->where('email', 'nuova.app@example.test')->firstOrFail();

    $this->postJson('/api/v1/auth/login', ['email' => 'nuova.app@example.test', 'password' => 'sbagliata'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    Notification::assertSentToTimes($nuovo, RegistrationLink::class, 1);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nuova.app@example.test',
        'password' => 'password-nuova-app',
        'code_challenge' => flowAccountApiChallenge($secondo),
    ])->assertStatus(403)->assertJsonPath('error.code', 'EMAIL_CONFIRMATION_PENDING');

    expect($nuovo->tokens()->count())->toBe(0);
    Notification::assertSentToTimes($nuovo, RegistrationLink::class, 2);

    $collegamenti = Notification::sent($nuovo, RegistrationLink::class)->map(fn (RegistrationLink $n): string => $n->url)->all();

    /* Il collegamento nuovo rende inutile il primo. */
    $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => flowAccountApiLinkToken($collegamenti[0]), 'code_verifier' => $primo])
        ->assertStatus(400);

    $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => flowAccountApiLinkToken($collegamenti[1]), 'code_verifier' => $secondo])
        ->assertOk()
        ->assertJsonPath('data.user.email_verified', true);

    $nuovo->refresh();
    expect($nuovo->email_claim_pending_at)->toBeNull()
        ->and(Hash::check('password-nuova-app', (string) $nuovo->password))->toBeTrue()
        ->and($nuovo->tokens()->count())->toBe(1);
});

it('accede con la password dall app con un token a scadenza per dispositivo, e risponde uguale a password e email sbagliate', function (): void {
    $user = User::factory()->create([
        'email' => 'entra.app@example.test',
        'password' => Hash::make('password-app-giusta'),
        'last_active_at' => now()->subMonth(),
    ]);

    $passwordSbagliata = $this->postJson('/api/v1/auth/login', ['email' => 'entra.app@example.test', 'password' => 'sbagliata']);
    $emailSconosciuta = $this->postJson('/api/v1/auth/login', ['email' => 'mai.vista.app@example.test', 'password' => 'sbagliata']);

    $passwordSbagliata->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    expect($emailSconosciuta->getStatusCode())->toBe(401)
        ->and($emailSconosciuta->json('error'))->toBe($passwordSbagliata->json('error'))
        ->and($user->tokens()->count())->toBe(0);

    $accesso = $this->postJson('/api/v1/auth/login', [
        'email' => 'entra.app@example.test',
        'password' => 'password-app-giusta',
        'device_name' => 'iPhone di Sara',
    ])->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'entra.app@example.test')
        ->assertJsonMissingPath('data.user.password');

    $token = PersonalAccessToken::findToken((string) $accesso->json('data.token'));

    expect($token?->tokenable_id)->toBe($user->getKey())
        ->and($token?->name)->toBe('iPhone di Sara')
        ->and($token?->expires_at?->isAfter(now()->addDays(config()->integer('api.auth.token_expiration_days') - 1)))->toBeTrue()
        ->and($accesso->json('data.expires_at'))->toBeString()
        ->and($user->fresh()->last_active_at?->isToday())->toBeTrue();

    $this->withToken((string) $accesso->json('data.token'))->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'entra.app@example.test');

    /* Senza nome del dispositivo il token prende quello predefinito. */
    flowAccountApiReset();
    $this->postJson('/api/v1/auth/login', ['email' => 'entra.app@example.test', 'password' => 'password-app-giusta'])->assertOk();

    expect($user->tokens()->count())->toBe(2)
        ->and($user->tokens()->pluck('name')->all())->toContain(__('api.auth.token_name'));
});

it('entra dall app con il collegamento senza password e butta fuori chi aveva registrato prima la casella', function (): void {
    /* Un intruso ha creato l'account con l'indirizzo di un altro e si è
       tenuto un token: la prima prova della casella lo deve escludere. */
    $user = User::factory()->unverified()->create([
        'email' => 'casella.app@example.test',
        'password' => Hash::make('scelta-da-un-intruso'),
    ]);
    $intruso = $user->createToken('Intruso')->plainTextToken;
    $verificatore = str_repeat('m', 43);

    $conosciuto = $this->postJson('/api/v1/auth/magic-link', ['email' => 'casella.app@example.test', 'code_challenge' => flowAccountApiChallenge($verificatore)])->assertOk();
    $sconosciuto = $this->postJson('/api/v1/auth/magic-link', ['email' => 'nessuno.app@example.test', 'code_challenge' => flowAccountApiChallenge($verificatore)])->assertOk();

    expect($conosciuto->json())->toBe($sconosciuto->json())
        ->and(MobileAuthChallenge::query()->count())->toBe(1);
    Notification::assertCount(1);

    $notifica = Notification::sent($user, MagicLoginLink::class)->first();
    $token = flowAccountApiLinkToken((string) $notifica->toMail($user)->actionUrl);

    $scambio = $this->postJson('/api/v1/auth/magic-link/exchange', [
        'token' => $token,
        'code_verifier' => $verificatore,
        'device_name' => 'Telefono del titolare',
    ])->assertOk()->assertJsonPath('data.user.email_verified', true);

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('scelta-da-un-intruso', (string) $user->password))->toBeFalse()
        ->and($user->tokens()->pluck('name')->all())->toBe(['Telefono del titolare'])
        ->and(MobileAuthChallenge::query()->where('user_id', $user->getKey())->count())->toBe(0);

    $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => $token, 'code_verifier' => $verificatore])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');

    $this->withToken($intruso)->getJson('/api/v1/me')->assertUnauthorized();

    flowAccountApiReset();
    $this->withToken((string) $scambio->json('data.token'))->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'casella.app@example.test');
});

it('dall app un account non confermato vede il profilo ma non i salvataggi, chiede il reinvio e conferma con il collegamento', function (): void {
    $user = User::factory()->unverified()->create([
        'email' => 'conferma.app@example.test',
        'password' => Hash::make('password-conferma-app'),
    ]);

    $bearer = (string) $this->postJson('/api/v1/auth/login', ['email' => 'conferma.app@example.test', 'password' => 'password-conferma-app'])
        ->assertOk()
        ->json('data.token');

    $this->withToken($bearer)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email_verified', false);
    $this->withToken($bearer)->getJson('/api/v1/me/saved')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
    $this->withToken($bearer)->postJson('/api/v1/me/devices', [
        'platform' => 'android',
        'installation_id' => 'installazione-non-confermata',
        'push_token' => 'fcm-non-confermato',
    ])->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
    expect($user->devices()->count())->toBe(0);

    flowAccountApiReset();
    $this->postJson('/api/v1/auth/verification/resend')->assertUnauthorized();
    Notification::assertNothingSent();

    $this->withToken($bearer)->postJson('/api/v1/auth/verification/resend')
        ->assertOk()
        ->assertJsonPath('data.message', __('account.verify.sent'));
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);

    $collegamento = (string) Notification::sent($user, VerifyEmailLink::class)->first()->toMail($user)->actionUrl;

    $this->withToken($bearer)->postJson('/api/v1/auth/verify-email', ['url' => str_replace('signature=', 'signature=0', $collegamento)])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $this->withToken($bearer)->postJson('/api/v1/auth/verify-email', ['url' => $collegamento])
        ->assertOk()
        ->assertJsonPath('data.message', __('account.api.email_verified'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    app('auth')->forgetGuards();
    $this->withToken($bearer)->getJson('/api/v1/me/saved')->assertOk()->assertJsonCount(0, 'data');

    /* Confermato, il reinvio risponde ma non spedisce. */
    app('auth')->forgetGuards();
    $this->withToken($bearer)->postJson('/api/v1/auth/verification/resend')->assertOk();
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);
});

it('reimposta dall app la password dimenticata, revoca ogni token e fa entrare solo con quella nuova', function (): void {
    $user = User::factory()->create([
        'email' => 'smemorata.app@example.test',
        'password' => Hash::make('vecchia-password-app'),
    ]);
    $telefono = $user->createToken('Telefono')->plainTextToken;
    $user->createToken('Tablet');

    $conosciuto = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'smemorata.app@example.test'])->assertOk();
    $sconosciuto = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nessuna.app@example.test'])->assertOk();

    expect($conosciuto->json())->toBe($sconosciuto->json());
    Notification::assertSentTo($user, ResetPasswordLink::class);
    Notification::assertCount(1);

    /* Il token che il messaggio porta, letto dalla notifica stessa. */
    $notifica = Notification::sent($user, ResetPasswordLink::class)->first();
    $token = (fn (): string => $this->token)->call($notifica);
    expect((string) $notifica->toMail($user)->actionUrl)->toContain($token);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'smemorata.app@example.test',
        'token' => 'un-token-inventato',
        'password' => 'nuova-password-app',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['token']]]);
    expect($user->tokens()->count())->toBe(2);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'smemorata.app@example.test',
        'token' => $token,
        'password' => 'nuova-password-app',
    ])->assertOk()->assertJsonPath('data.message', __('api.auth.password_reset'));

    expect($user->tokens()->count())->toBe(0)
        ->and(Hash::check('nuova-password-app', (string) $user->fresh()->password))->toBeTrue();

    $this->withToken($telefono)->getJson('/api/v1/me')->assertUnauthorized();

    flowAccountApiReset();
    $this->postJson('/api/v1/auth/login', ['email' => 'smemorata.app@example.test', 'password' => 'vecchia-password-app'])
        ->assertStatus(401);
    $this->postJson('/api/v1/auth/login', ['email' => 'smemorata.app@example.test', 'password' => 'nuova-password-app'])
        ->assertOk();
    expect($user->tokens()->count())->toBe(1);
});
