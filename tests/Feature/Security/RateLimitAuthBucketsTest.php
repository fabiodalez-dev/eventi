<?php

declare(strict_types=1);

use App\Filament\Auth\Login;
use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Notifications\VerifyEmailLink;
use App\Support\AccountLoginThrottle;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/**
 * Il tetto per account di `api-auth` e `account-auth` non deve diventare una
 * leva per chiudere fuori qualcuno.
 *
 * - Le richieste senza email finivano tutte nel secchio `conto:` vuoto, uno
 *   solo per l'intero sito: poche richieste anonime bloccavano a tutti lo
 *   scambio del collegamento di accesso, la verifica e il reinvio della
 *   conferma.
 * - Accesso, collegamento di accesso e password dimenticata condividevano lo
 *   stesso secchio, contato anche sugli accessi riusciti: chi conosce l'email
 *   teneva fuori il titolare da ogni porta.
 *
 * Il tetto è abbassato a 2 per non fare cento richieste per test; gli
 * indirizzi cambiano a ogni giro per restare sotto il limite della coppia.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Notification::fake();
    config()->set('api.rate_limit.attempts_per_account', 2);
});

it('non fa condividere a tutti lo stesso secchio alle richieste API senza email', function (): void {
    foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/magic-link/exchange', [])
            ->assertStatus(422);
    }

    /* Un utente vero, da un altro indirizzo, scambia il proprio collegamento. */
    $utente = User::factory()->create();
    $verificatore = str_repeat('v', 64);
    $gettone = str_repeat('g', 64);
    MobileAuthChallenge::query()->create([
        'user_id' => $utente->id,
        'token_hash' => hash('sha256', $gettone),
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verificatore, true)), '+/', '-_'), '='),
        'password_fingerprint' => MagicLoginLink::fingerprint($utente),
        'expires_at' => CarbonImmutable::now()->addMinutes(15),
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])
        ->postJson('/api/v1/auth/magic-link/exchange', ['token' => $gettone, 'code_verifier' => $verificatore, 'device_name' => 'Telefono'])
        ->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.51'])
        ->postJson('/api/v1/auth/verify-email', ['url' => 'https://example.test/finto'])
        ->assertStatus(400);
});

it('conta il reinvio della conferma sul singolo utente, non su un secchio comune', function (): void {
    foreach (['198.51.100.11', '198.51.100.12', '198.51.100.13'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/verify-email', []);
    }

    $primo = User::factory()->unverified()->create();
    Sanctum::actingAs($primo);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->postJson('/api/v1/auth/verification/resend')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])->postJson('/api/v1/auth/verification/resend')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])->postJson('/api/v1/auth/verification/resend')->assertStatus(429);

    /* Il tetto del primo non tocca il secondo. */
    $secondo = User::factory()->unverified()->create();
    Sanctum::actingAs($secondo);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->postJson('/api/v1/auth/verification/resend')->assertOk();
});

it('lascia rispedire la conferma dal sito anche dopo richieste anonime senza email', function (): void {
    foreach (['198.51.100.31', '198.51.100.32', '198.51.100.33', '198.51.100.34'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('account.login.store'), ['password' => 'qualunque']);
    }

    $utente = User::factory()->unverified()->create();
    $this->actingAs($utente)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
        ->post(route('account.verification.send'))
        ->assertRedirect();

    Notification::assertSentTo($utente, VerifyEmailLink::class);
});

it('lascia al titolare il collegamento di accesso e la password dimenticata quando qualcuno prova password sulla sua email', function (): void {
    $vittima = User::factory()->create(['email' => 'titolare@example.test']);

    foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/login', ['email' => $vittima->email, 'password' => 'sbagliata']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->postJson('/api/v1/auth/magic-link', ['email' => $vittima->email, 'code_challenge' => str_repeat('a', 43)])
        ->assertSuccessful();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
        ->postJson('/api/v1/auth/password/forgot', ['email' => $vittima->email])
        ->assertSuccessful();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
        ->post(route('account.magic-link.store'), ['email' => $vittima->email])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('non conta gli accessi riusciti nel tetto per account', function (): void {
    $utente = User::factory()->create(['email' => 'spesso@example.test', 'password' => 'password-lunga-1']);

    foreach (['203.0.113.21', '203.0.113.22', '203.0.113.23', '203.0.113.24'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/login', ['email' => $utente->email, 'password' => 'password-lunga-1'])
            ->assertOk();
    }
});

/* Il contrappeso: la forza bruta distribuita resta fermata, sul sito e nell'API. */
it('ferma ancora i tentativi sbagliati sullo stesso account da indirizzi diversi', function (): void {
    $vittima = User::factory()->create(['email' => 'bersaglio@example.test']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.31'])->postJson('/api/v1/auth/login', ['email' => $vittima->email, 'password' => 'a'])->assertStatus(401);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.32'])->postJson('/api/v1/auth/login', ['email' => $vittima->email, 'password' => 'b'])->assertStatus(401);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.33'])->postJson('/api/v1/auth/login', ['email' => $vittima->email, 'password' => 'c'])->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])->post(route('account.login.store'), ['email' => $vittima->email, 'password' => 'a'])->assertSessionHasErrors('email');
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])->post(route('account.login.store'), ['email' => $vittima->email, 'password' => 'b'])->assertSessionHasErrors('email');
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.43'])->post(route('account.login.store'), ['email' => $vittima->email, 'password' => 'c'])->assertStatus(429);
});

it('non chiude il pannello a chi entra più volte con la password giusta', function (): void {
    $gestore = User::factory()->create(['password' => 'password-lunga-1']);
    $gestore->assignRole('admin');
    $this->get('/admin/login')->assertOk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    foreach (['192.0.2.1', '192.0.2.2', '192.0.2.3'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        Livewire::test(Login::class)
            ->fillForm(['email' => $gestore->email, 'password' => 'password-lunga-1'])
            ->call('authenticate')
            ->assertHasNoErrors();
        auth()->logout();
    }
});

/*
 * Il tetto si conta PRIMA della verifica della password, e si restituisce a
 * password giusta. Contato a risposta arrivata, tutte le richieste in volo
 * durante il bcrypt e il timebox trovavano il contatore ancora basso: con un
 * pool di indirizzi in parallelo il tetto di venti non reggeva.
 */
it('conta il tentativo sull API mentre la password è ancora in verifica, e lo restituisce a password giusta', function (): void {
    $utente = User::factory()->create(['email' => 'inverifica@example.test', 'password' => 'password-lunga-1']);
    $chiave = AccountLoginThrottle::chiave('api', $utente->email);
    $visti = [];

    $hasher = Mockery::mock(app('hash'));
    $hasher->shouldReceive('check')->andReturnUsing(function (string $password, ?string $hash) use (&$visti, $chiave): bool {
        $visti[] = (int) RateLimiter::attempts($chiave);

        return $password === 'password-lunga-1';
    });
    Hash::swap($hasher);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])->postJson('/api/v1/auth/login', ['email' => $utente->email, 'password' => 'sbagliata'])->assertStatus(401);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.52'])->postJson('/api/v1/auth/login', ['email' => $utente->email, 'password' => 'password-lunga-1'])->assertOk();

    /* Durante la prima verifica il tentativo è già contato; durante la
       seconda c'è anche il fallimento di prima. Dopo l'accesso riuscito resta
       solo il fallimento. */
    expect($visti)->toBe([1, 2])
        ->and((int) RateLimiter::attempts($chiave))->toBe(1);
});

it('conta il tentativo sul sito mentre la password è ancora in verifica', function (): void {
    $utente = User::factory()->create(['email' => 'sito-inverifica@example.test', 'password' => 'password-lunga-1']);
    $chiave = AccountLoginThrottle::chiave('sito', $utente->email);
    $visti = [];
    EventFacade::listen(Attempting::class, function () use (&$visti, $chiave): void {
        $visti[] = (int) RateLimiter::attempts($chiave);
    });

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.61'])->post(route('account.login.store'), ['email' => $utente->email, 'password' => 'sbagliata'])->assertSessionHasErrors('email');
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.62'])->post(route('account.login.store'), ['email' => $utente->email, 'password' => 'password-lunga-1'])->assertRedirect();

    expect($visti)->toBe([1, 2])
        ->and((int) RateLimiter::attempts($chiave))->toBe(1);
});

it('conta il tentativo sul pannello mentre la password è ancora in verifica', function (): void {
    $gestore = User::factory()->create(['password' => 'password-lunga-1']);
    $gestore->assignRole('admin');
    $chiave = AccountLoginThrottle::chiave('pannello', $gestore->email);
    $visti = [];
    EventFacade::listen(Attempting::class, function () use (&$visti, $chiave): void {
        $visti[] = (int) RateLimiter::attempts($chiave);
    });
    $this->get('/admin/login')->assertOk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.31']);
    Livewire::test(Login::class)->fillForm(['email' => $gestore->email, 'password' => 'sbagliata'])
        ->call('authenticate')->assertHasErrors(['data.email']);
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.32']);
    Livewire::test(Login::class)->fillForm(['email' => $gestore->email, 'password' => 'password-lunga-1'])
        ->call('authenticate')->assertHasNoErrors();

    /* Filament annuncia il tentativo più di una volta per richiesta: conta
       che ognuno trovi già contato quello in corso. */
    expect(array_values(array_unique($visti)))->toBe([1, 2])
        ->and((int) RateLimiter::attempts($chiave))->toBe(1);
});

it('a tetto pieno respinge l accesso prima di verificare la password', function (): void {
    $utente = User::factory()->create(['email' => 'pieno@example.test', 'password' => 'password-lunga-1']);
    $chiave = AccountLoginThrottle::chiave('api', $utente->email);
    RateLimiter::increment($chiave, 900, 2);

    $hasher = Mockery::mock(app('hash'));
    $hasher->shouldNotReceive('check');
    Hash::swap($hasher);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.71'])
        ->postJson('/api/v1/auth/login', ['email' => $utente->email, 'password' => 'password-lunga-1'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    /* Il rifiuto non consuma niente: il contatore resta al tetto. */
    expect((int) RateLimiter::attempts($chiave))->toBe(2);
});
