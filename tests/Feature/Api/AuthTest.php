<?php

declare(strict_types=1);

use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Notifications\ResetPasswordLink;
use App\Notifications\VerifyEmailLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * I ruoli globali sono dati di base creati dal seeder: chi si registra prende
 * il ruolo `user`, che è il gradino più basso di §3 del piano.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
});

it('keeps separate registration names and validates an explicitly supplied confirmation', function (): void {
    Notification::fake();
    $data = ['first_name' => 'Giulia', 'last_name' => 'Rossi', 'email' => 'separate@example.test', 'password' => 'una-password-lunga', 'password_confirmation' => 'diversa'];
    $this->postJson('/api/v1/auth/register', $data)->assertUnprocessable();
    $data['password_confirmation'] = $data['password'];
    $this->postJson('/api/v1/auth/register', $data)->assertCreated()->assertJsonPath('data.user.name', 'Giulia Rossi');
    $user = User::where('email', $data['email'])->firstOrFail();
    expect($user->first_name)->toBe('Giulia')->and($user->last_name)->toBe('Rossi')->and($user->marketing_opt_in_at)->toBeNull();
});

it('registra un utente e gli consegna un token', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Giulia Rossi',
        'email' => 'giulia@example.test',
        'password' => 'una-password-molto-lunga',
        'device_name' => 'iPhone di Giulia',
    ])->assertCreated();

    $token = $response->json('data.token');

    expect($token)->toBeString()
        ->and($response->json('data.token_type'))->toBe('Bearer')
        ->and($response->json('data.expires_at'))->toBeString()
        ->and($response->json('data.user.email'))->toBe('giulia@example.test')
        ->and($response->json('data.user'))->not->toHaveKey('password');

    $user = User::query()->where('email', 'giulia@example.test')->firstOrFail();

    expect(Hash::check('una-password-molto-lunga', (string) $user->password))->toBeTrue()
        /* Il fuso lo scrive il database, ed è quello della città servita. */
        ->and($user->timezone)->toBe('Europe/Rome')
        /* Il consenso marketing è un'altra cosa e nasce spento (§15.9). */
        ->and($user->marketing_opt_in_at)->toBeNull()
        ->and($user->hasRole('user'))->toBeTrue()
        ->and($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->first()?->name)->toBe('iPhone di Giulia')
        ->and($user->tokens()->first()?->expires_at)->not->toBeNull();
});

it('genera un magic link mobile opaco senza rivelare se l account esiste', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'magic@example.test']);

    $known = $this->postJson('/api/v1/auth/magic-link', ['code_challenge' => str_repeat('a', 43), 'email' => 'magic@example.test'])->assertOk();
    $unknown = $this->postJson('/api/v1/auth/magic-link', ['code_challenge' => str_repeat('a', 43), 'email' => 'mai-vista@example.test'])->assertOk();

    expect($known->json('data.message'))->toBe($unknown->json('data.message'))
        ->and(MobileAuthChallenge::query()->count())->toBe(1)
        ->and(MobileAuthChallenge::query()->first()?->token_hash)->toHaveLength(64);

    Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;

        return is_string($url)
            && str_contains($url, '/app/auth/magic?token=')
            && is_string($token)
            && mb_strlen($token) === 64
            && MobileAuthChallenge::query()->where('token_hash', hash('sha256', $token))->exists();
    });
    Notification::assertCount(1);
});

it('reinvia la verifica email soltanto all utente autenticato non verificato', function (): void {
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $token = $user->createToken('Android')->plainTextToken;

    $this->postJson('/api/v1/auth/verification/resend')->assertUnauthorized();

    $this->withToken($token)->postJson('/api/v1/auth/verification/resend')->assertOk();
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);

    $user->markEmailAsVerified();
    $this->app->make('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/auth/verification/resend')->assertOk();
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);
});

it('registra il consenso marketing con una data propria solo se richiesto', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Marco',
        'email' => 'marco@example.test',
        'password' => 'una-password-molto-lunga',
        'marketing_opt_in' => true,
    ])->assertCreated();

    expect(User::query()->where('email', 'marco@example.test')->firstOrFail()->marketing_opt_in_at)->not->toBeNull();
});

it('rifiuta una registrazione con email già usata o password debole', function (): void {
    User::factory()->create(['email' => 'gia@example.test']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Chiunque',
        'email' => 'gia@example.test',
        'password' => 'una-password-molto-lunga',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['email']]]);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Chiunque',
        'email' => 'nuovo@example.test',
        'password' => 'corta',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['password']]]);
});

it('fa accedere con le credenziali giuste e nega quelle sbagliate', function (): void {
    User::factory()->create([
        'email' => 'entra@example.test',
        'password' => Hash::make('la-password-giusta'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'entra@example.test',
        'password' => 'la-password-giusta',
    ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'entra@example.test',
        'password' => 'quella-sbagliata',
    ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

/*
 * §16: una risposta diversa per "email inesistente" e "password sbagliata"
 * direbbe a chiunque quali indirizzi sono registrati.
 */
it('non lascia capire quali indirizzi sono registrati', function (): void {
    User::factory()->create(['email' => 'esiste@example.test', 'password' => Hash::make('la-password-giusta')]);

    $esistente = $this->postJson('/api/v1/auth/login', ['email' => 'esiste@example.test', 'password' => 'sbagliata']);
    $inesistente = $this->postJson('/api/v1/auth/login', ['email' => 'mai.visto@example.test', 'password' => 'sbagliata']);

    expect($esistente->getStatusCode())->toBe($inesistente->getStatusCode())
        ->and($esistente->json('error.code'))->toBe($inesistente->json('error.code'));
});

it('revoca soltanto il token con cui si esce', function (): void {
    $user = User::factory()->create();

    $telefono = $user->createToken('telefono')->plainTextToken;
    $user->createToken('tablet');

    $this->withHeader('Authorization', 'Bearer '.$telefono)
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    expect($user->tokens()->count())->toBe(1)
        ->and($user->tokens()->first()?->name)->toBe('tablet');

    /* Il token appena revocato non apre più nulla. La guardia va dimenticata
       perché nei test l'istanza sopravvive fra due richieste e terrebbe in
       memoria l'utente della prima. */
    $this->app->make('auth')->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$telefono)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('nega l\'uscita a chi non ha alcun token', function (): void {
    $this->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('manda il collegamento per reimpostare la password e risponde sempre allo stesso modo', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'smemorata@example.test']);

    $conosciuto = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'smemorata@example.test'])->assertOk();
    $sconosciuto = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'mai.visto@example.test'])->assertOk();

    expect($conosciuto->json('data.message'))->toBe($sconosciuto->json('data.message'));

    Notification::assertSentTo($user, ResetPasswordLink::class);
    Notification::assertCount(1);
});

it('reimposta la password con un token valido e butta fuori i dispositivi', function (): void {
    $user = User::factory()->create([
        'email' => 'reimposta@example.test',
        'password' => Hash::make('la-vecchia-password'),
    ]);

    $user->createToken('telefono');

    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'reimposta@example.test',
        'token' => $token,
        'password' => 'la-nuova-password-lunga',
    ])->assertOk();

    $user->refresh();

    expect(Hash::check('la-nuova-password-lunga', (string) $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    /* Un token già speso non vale una seconda volta. */
    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'reimposta@example.test',
        'token' => $token,
        'password' => 'un-altro-tentativo-lungo',
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('il token consegnato dall\'accesso apre le rotte protette', function (): void {
    $user = User::factory()->create([
        'email' => 'entra@example.test',
        'password' => Hash::make('la-password-giusta'),
    ]);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'entra@example.test',
        'password' => 'la-password-giusta',
    ])->json('data.token');

    expect(PersonalAccessToken::findToken($token)?->tokenable_id)->toBe($user->getKey());

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/auth/logout')
        ->assertOk();
});

/*
 * §16: accesso, registrazione e reimpostazione hanno un limite di frequenza
 * proprio e molto più stretto del resto dell'API.
 */
it('ferma chi prova password a raffica', function (): void {
    User::factory()->create(['email' => 'bersaglio@example.test', 'password' => Hash::make('la-password-giusta')]);

    for ($tentativo = 0; $tentativo < 6; $tentativo++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'bersaglio@example.test',
            'password' => 'tentativo-'.$tentativo,
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'bersaglio@example.test',
        'password' => 'la-password-giusta',
    ])->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});
