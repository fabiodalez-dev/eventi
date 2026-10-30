<?php

declare(strict_types=1);

use App\Actions\Account\ClaimMailbox;
use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Notifications\RegistrationLink;
use App\Notifications\VerifyEmailLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/**
 * L'account creato in anticipo con l'indirizzo di un altro.
 *
 * Registrarsi non prova di possedere la casella: chi lo fa con l'email della
 * vittima sceglie la password e si tiene il token. Quando la vittima dimostra
 * la casella per la prima volta — collegamento di accesso, sul sito o
 * nell'app — tutto ciò che è nato prima deve smettere di valere.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Notification::fake();
});

/**
 * L'intruso si registra dall'API con l'indirizzo della vittima.
 *
 * Dal D64 la registrazione risponde 202 senza token (la registrazione non
 * rivela più se un indirizzo esiste): il token che qui l'intruso «si tiene»
 * è quello che un account registrato prima di quel cambio può avere ancora,
 * e che `ClaimMailbox` deve continuare a revocare.
 */
function registraIntruso(string $email = 'vittima@example.test'): array
{
    test()->postJson('/api/v1/auth/register', [
        'name' => 'Intruso',
        'email' => $email,
        'password' => 'Password-Intruso-123',
        'device_name' => 'Telefono intruso',
    ])->assertStatus(202)->assertJsonMissingPath('data.token');

    $user = User::query()->where('email', $email)->firstOrFail();

    return [$user, $user->createToken('Telefono intruso')->plainTextToken];
}

function sfidaMobile(User $user, string $verificatore, string $gettone): void
{
    MobileAuthChallenge::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $gettone),
        'password_fingerprint' => MagicLoginLink::fingerprint($user),
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verificatore, true)), '+/', '-_'), '='),
        'expires_at' => now()->addMinutes(15),
    ]);
}

function credenzialiIntrusoMorte(string $token, string $email = 'vittima@example.test'): void
{
    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => ''])
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
        ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password-Intruso-123'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
}

it('azzera password e token dell intruso quando la vittima entra con il collegamento del sito', function (): void {
    [$user, $token] = registraIntruso();

    $this->post(MagicLoginLink::url($user))->assertRedirect(route('account.feed'));

    $this->assertAuthenticatedAs($user);
    $user->refresh();
    expect($user->tokens()->count())->toBe(0)
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->email_claim_pending_at)->toBeNull();

    auth()->logout();
    credenzialiIntrusoMorte($token);
});

it('azzera password e token dell intruso quando la vittima entra dall app', function (): void {
    [$user, $token] = registraIntruso();
    sfidaMobile($user, str_repeat('v', 43), str_repeat('t', 64));

    $nuovo = $this->postJson('/api/v1/auth/magic-link/exchange', [
        'token' => str_repeat('t', 64), 'code_verifier' => str_repeat('v', 43), 'device_name' => 'Telefono vittima',
    ])->assertOk()->json('data.token');

    expect($user->fresh()->tokens()->count())->toBe(1);
    credenzialiIntrusoMorte($token);

    /* Il token appena emesso, invece, funziona. */
    $this->app['auth']->forgetGuards();
    $this->withToken((string) $nuovo)->getJson('/api/v1/me')->assertOk();
});

it('non butta fuori dall app chi si è iscritto dal telefono e conferma dal browser', function (): void {
    [$user, $token] = registraIntruso('giulia@example.test');

    /* La conferma aperta senza sessione: il caso normale di chi si iscrive
       dall'app. Il token resta, l'indirizzo è verificato. */
    $this->get(VerifyEmailLink::url($user))->assertRedirect(route('login'));

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->tokens()->count())->toBe(1)
        ->and($user->email_claim_pending_at)->not->toBeNull();
    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
});

it('azzera le credenziali anche se la vittima ha confermato senza sessione prima di entrare', function (): void {
    [$user, $token] = registraIntruso();

    /* Prima la conferma dal telefono, senza sessione: verifica e basta. */
    $this->get(VerifyEmailLink::url($user))->assertRedirect(route('login'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    /* Poi il primo accesso con il collegamento: la password l'ha scelta
       qualcun altro, e qui smette di valere. */
    $this->post(MagicLoginLink::url($user->fresh()))->assertRedirect(route('account.feed'));
    expect($user->fresh()->tokens()->count())->toBe(0);

    auth()->logout();
    credenzialiIntrusoMorte($token);
});

it('chi conferma con il proprio token dall API dimostra la casella e non perde niente dopo', function (): void {
    [$user, $token] = registraIntruso('marco@example.test');

    $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['url' => VerifyEmailLink::url($user)])->assertOk();
    expect($user->fresh()->email_claim_pending_at)->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->post(MagicLoginLink::url($user->fresh()))->assertRedirect(route('account.feed'));

    expect($user->fresh()->tokens()->count())->toBe(1);
});

it('non tocca token e password di chi ha già dimostrato la casella', function (): void {
    $user = User::factory()->create(['password' => 'password-di-sempre-1']);
    $user->createToken('tablet');

    $this->post(MagicLoginLink::url($user))->assertRedirect(route('account.feed'));
    auth()->logout();

    expect($user->fresh()->tokens()->count())->toBe(1);
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password-di-sempre-1'])->assertOk();
});

it('lascia collegato chi si registra dal sito e apre il collegamento dalla stessa sessione', function (): void {
    /* Dal D64 la registrazione non fa entrare: risponde «controlla la posta»
       e si entra dal collegamento, che dalla stessa sessione chiude il
       sospeso senza toccare la password. */
    $this->post(route('account.register.store'), [
        'name' => 'Sara',
        'email' => 'sara@example.test',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
    ])->assertRedirect(route('account.register.sent'));
    $this->assertGuest();

    $user = User::query()->where('email', 'sara@example.test')->firstOrFail();
    expect($user->email_claim_pending_at)->not->toBeNull();

    $this->get(Notification::sent($user, RegistrationLink::class)->first()->url)->assertRedirect();

    $this->assertAuthenticatedAs($user);
    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue()->and($user->email_claim_pending_at)->toBeNull();
    $this->app['auth']->forgetGuards();
    $this->get(route('account.profile'))->assertOk();
});

it('la conferma aperta con la sessione di un altro account non chiude il sospeso', function (): void {
    [$user] = registraIntruso();
    $altro = User::factory()->create();

    $this->actingAs($altro)->get(VerifyEmailLink::url($user))->assertRedirect(route('account.profile'));

    expect($user->fresh()->email_claim_pending_at)->not->toBeNull();
});

it('reimpostare la password chiude il sospeso della registrazione', function (): void {
    [$user] = registraIntruso();

    event(new PasswordReset($user));

    expect($user->fresh()->email_claim_pending_at)->toBeNull()->and($user->tokens()->count())->toBe(0);
});

it('cancella le sessioni del sito dell intruso con il driver database', function (): void {
    [$user] = registraIntruso();
    config()->set('session.driver', 'database');
    DB::table('sessions')->insert(['id' => 'sessione-intruso', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    DB::table('sessions')->insert(['id' => 'sessione-altrui', 'user_id' => null, 'payload' => '', 'last_activity' => time()]);
    $ricordami = $user->remember_token;

    app(ClaimMailbox::class)($user);

    expect(DB::table('sessions')->where('id', 'sessione-intruso')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'sessione-altrui')->exists())->toBeTrue()
        ->and($user->fresh()->remember_token)->not->toBe($ricordami);
});

it('i collegamenti spediti prima della rivendicazione non valgono più', function (): void {
    [$user] = registraIntruso();
    $vecchio = URL::temporarySignedRoute('account.magic-link.login', now()->addMinutes(15), [
        'user' => $user->id, 'fingerprint' => MagicLoginLink::fingerprint($user),
    ]);
    sfidaMobile($user, str_repeat('w', 43), str_repeat('u', 64));
    $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => str_repeat('u', 64), 'code_verifier' => str_repeat('w', 43)])->assertOk();

    /* La password è cambiata: anche i collegamenti già spediti all'intruso
       (o chiesti da lui) smettono di valere. */
    $this->post($vecchio)->assertRedirect(route('account.magic-link'));
    $this->assertGuest();
});

it('la password reimpostata con l indirizzo ancora da confermare resta valida al primo collegamento', function (): void {
    [$user] = registraIntruso('anna@example.test');
    expect($user->hasVerifiedEmail())->toBeFalse();

    /* La conferma persa, poi «password dimenticata»: il gettone arriva nella
       casella, quindi la reimpostazione prova anche l'indirizzo. */
    $gettone = Password::broker()->createToken($user);
    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'anna@example.test',
        'token' => $gettone,
        'password' => 'Password-Nuova-di-Anna-1',
        'password_confirmation' => 'Password-Nuova-di-Anna-1',
    ])->assertOk();

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue()->and($user->email_claim_pending_at)->toBeNull();

    $app = $this->postJson('/api/v1/auth/login', ['email' => 'anna@example.test', 'password' => 'Password-Nuova-di-Anna-1', 'device_name' => 'Telefono'])
        ->assertOk()->json('data.token');

    /* Più tardi entra dal sito con il collegamento: niente azzeramento. */
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->post(MagicLoginLink::url($user->fresh()))->assertRedirect(route('account.feed'));
    auth()->logout();

    $this->app['auth']->forgetGuards();
    $this->withToken((string) $app)->getJson('/api/v1/me')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => ''])
        ->postJson('/api/v1/auth/login', ['email' => 'anna@example.test', 'password' => 'Password-Nuova-di-Anna-1', 'device_name' => 'Tablet'])
        ->assertOk();
});

it('la conferma aperta dal telefono senza sessione offre di completarla nell app ufficiale', function (): void {
    [$user, $token] = registraIntruso('luca@example.test');
    $collegamento = VerifyEmailLink::url($user);

    $pagina = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36'])
        ->get($collegamento)
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect((string) $pagina->headers->get('Cache-Control'))->toContain('no-store');

    /* L'intent nomina il pacchetto dell'app: Android lo consegna solo a lei. */
    $html = html_entity_decode((string) $pagina->getContent());
    expect(preg_match('/href="(intent:\/\/auth\/verify-email\?url=([^#"]+)#Intent;scheme=incitta;package=it\.fabiodalez\.incitta;[^"]*;end)"/', $html, $trovato))->toBe(1);
    $riportato = rawurldecode($trovato[2]);
    expect($riportato)->toBe($collegamento);

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue()->and($user->email_claim_pending_at)->not->toBeNull();

    /* L'app rimanda lo stesso collegamento col proprio token: la seconda
       verifica dello stesso indirizzo chiude il sospeso. */
    $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['url' => $riportato])->assertOk();
    expect($user->fresh()->email_claim_pending_at)->toBeNull();

    /* E il primo collegamento di accesso non butta più fuori l'app. */
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->post(MagicLoginLink::url($user->fresh()))->assertRedirect(route('account.feed'));
    auth()->logout();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
});

it('il collegamento riportato nell app con il token di un altro account non chiude il sospeso', function (): void {
    [$user] = registraIntruso();
    $altro = User::factory()->create()->createToken('Telefono altrui')->plainTextToken;

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 15) Mobile'])->get(VerifyEmailLink::url($user))->assertOk();
    $this->withToken($altro)->postJson('/api/v1/auth/verify-email', ['url' => VerifyEmailLink::url($user)])->assertOk();

    expect($user->fresh()->email_claim_pending_at)->not->toBeNull();
});

it('senza sospeso la conferma dal telefono porta all accesso come prima', function (): void {
    $user = User::factory()->unverified()->create();

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 15) Mobile'])
        ->get(VerifyEmailLink::url($user))
        ->assertRedirect(route('login'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
