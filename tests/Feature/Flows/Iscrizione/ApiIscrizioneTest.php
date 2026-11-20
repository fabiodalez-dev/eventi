<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\AccountAlreadyRegistered;
use App\Notifications\RegistrationLink;
use App\Notifications\ResetPasswordLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * L'iscrizione e l'accesso dall'app (`/api/v1/auth/*`, `/api/v1/me`, D64):
 * la forma degli errori che l'app legge, l'indirizzo comunque scritto, i
 * token per dispositivo e la loro revoca, i limiti dei tentativi. La forma
 * delle risposte è quella che l'app Android già usa: qui si controlla, non
 * si cambia.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Notification::fake();
});

/** Una nuova richiesta dell'app: nessuna intestazione né utente ricordato dalla precedente. */
function iscrizioneApiNuovaRichiesta(): void
{
    test()->flushHeaders();
    app('auth')->forgetGuards();
}

function iscrizioneApiAccedi(string $email, string $password, string $dispositivo): string
{
    iscrizioneApiNuovaRichiesta();

    return (string) test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, 'device_name' => $dispositivo])
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->json('data.token');
}

it('risponde alla registrazione sbagliata con gli errori campo per campo e senza creare niente', function (): void {
    $this->postJson('/api/v1/auth/register', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['email', 'password']]]);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'non-un-indirizzo',
        'password' => 'corta',
        'code_challenge' => 'troppo-corta',
    ])->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['email', 'password', 'code_challenge']]]);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'conferma.diversa@example.test',
        'password' => 'password-lunga-app',
        'password_confirmation' => 'un-altra-password-app',
    ])->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['password_confirmation']]])
        ->assertJsonMissingPath('error.fields.email');

    expect(User::query()->count())->toBe(0);
    Notification::assertNothingSent();

    /* Corretti i dati, la risposta è il 202 di sempre. */
    $this->postJson('/api/v1/auth/register', [
        'email' => 'conferma.diversa@example.test',
        'password' => 'password-lunga-app',
        'password_confirmation' => 'password-lunga-app',
    ])->assertStatus(202)->assertJsonPath('data.message', __('api.auth.registration_sent'));
    expect(User::query()->where('email', 'conferma.diversa@example.test')->count())->toBe(1);
});

it('dall app riconosce un indirizzo registrato scritto con le maiuscole e non crea un doppione', function (): void {
    $esistente = User::factory()->create(['email' => 'giorgio.app@example.test', 'password' => Hash::make('password-di-giorgio')]);

    $nuovo = $this->postJson('/api/v1/auth/register', [
        'email' => 'nuova.persona.app@example.test',
        'password' => 'password-nuova-app',
    ])->assertStatus(202);
    $giaUsato = $this->postJson('/api/v1/auth/register', [
        'email' => 'GIORGIO.App@Example.TEST',
        'password' => 'password-di-un-intruso',
    ])->assertStatus(202);

    expect($giaUsato->json())->toBe($nuovo->json())
        ->and(User::query()->where('email', 'giorgio.app@example.test')->count())->toBe(1)
        ->and(Hash::check('password-di-giorgio', (string) $esistente->fresh()->password))->toBeTrue();
    Notification::assertSentTo($esistente, AccountAlreadyRegistered::class);
    Notification::assertNotSentTo($esistente, RegistrationLink::class);

    /* Il titolare entra come sempre, con la sua password. */
    iscrizioneApiAccedi('giorgio.app@example.test', 'password-di-giorgio', 'Telefono di Giorgio');
});

it('dall app entra con l indirizzo in maiuscolo, legge il proprio profilo ed esce solo dal telefono', function (): void {
    $user = User::factory()->create(['email' => 'due.dispositivi.app@example.test', 'password' => Hash::make('password-due-dispositivi'), 'name' => 'Nora']);

    $telefono = iscrizioneApiAccedi('  DUE.Dispositivi.App@example.test ', 'password-due-dispositivi', 'Telefono di Nora');
    $tablet = iscrizioneApiAccedi('due.dispositivi.app@example.test', 'password-due-dispositivi', 'Tablet di Nora');
    expect($user->tokens()->pluck('name')->sort()->values()->all())->toBe(['Tablet di Nora', 'Telefono di Nora']);

    iscrizioneApiNuovaRichiesta();
    $this->withToken($telefono)->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.email', 'due.dispositivi.app@example.test')
        ->assertJsonPath('data.name', 'Nora');

    iscrizioneApiNuovaRichiesta();
    $this->withToken($telefono)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data.message', __('api.auth.logged_out'));

    iscrizioneApiNuovaRichiesta();
    $this->withToken($telefono)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    iscrizioneApiNuovaRichiesta();
    $this->withToken($telefono)->postJson('/api/v1/auth/logout')->assertUnauthorized();

    iscrizioneApiNuovaRichiesta();
    $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
    expect($user->tokens()->pluck('name')->all())->toBe(['Tablet di Nora']);
});

it('risponde 401 nello stesso formato a chi non manda il token o ne manda uno inventato', function (): void {
    $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

    iscrizioneApiNuovaRichiesta();
    $this->withToken('1|gettone-inventato-che-non-esiste')->getJson('/api/v1/me')
        ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

    iscrizioneApiNuovaRichiesta();
    $this->withToken('1|gettone-inventato-che-non-esiste')->patchJson('/api/v1/me', ['name' => 'Intruso'])->assertUnauthorized();
    iscrizioneApiNuovaRichiesta();
    $this->withToken('1|gettone-inventato-che-non-esiste')->deleteJson('/api/v1/me')->assertUnauthorized();
});

it('dall app ferma chi prova troppe password sullo stesso indirizzo e lo lascia entrare passato il minuto', function (): void {
    $user = User::factory()->create(['email' => 'forza.bruta.app@example.test', 'password' => Hash::make('password-giusta-app')]);

    foreach (range(1, config()->integer('api.rate_limit.auth')) as $volta) {
        iscrizioneApiNuovaRichiesta();
        $this->postJson('/api/v1/auth/login', ['email' => 'forza.bruta.app@example.test', 'password' => 'tentativo-'.$volta])
            ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    iscrizioneApiNuovaRichiesta();
    $this->postJson('/api/v1/auth/login', ['email' => 'forza.bruta.app@example.test', 'password' => 'password-giusta-app'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertHeader('Retry-After');
    expect($user->tokens()->count())->toBe(0);

    $this->travel(61)->seconds();
    iscrizioneApiAccedi('forza.bruta.app@example.test', 'password-giusta-app', 'Telefono');
    expect($user->tokens()->count())->toBe(1);
});

it('dall app conta i tentativi sullo stesso account comunque si scriva l indirizzo e da qualunque rete', function (): void {
    config(['api.rate_limit.attempts_per_account' => 2]);
    User::factory()->create(['email' => 'conto.unico@example.test', 'password' => Hash::make('password-conto-unico')]);

    foreach (['conto.unico@example.test', 'CONTO.UNICO@example.test'] as $i => $variante) {
        iscrizioneApiNuovaRichiesta();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.(30 + $i)])
            ->postJson('/api/v1/auth/login', ['email' => $variante, 'password' => 'sbagliata-'.$i])
            ->assertUnauthorized();
    }

    iscrizioneApiNuovaRichiesta();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->postJson('/api/v1/auth/login', ['email' => 'Conto.Unico@Example.Test', 'password' => 'password-conto-unico'])
        ->assertStatus(429);

    /* Il secchio del sito è un altro: dal sito il titolare entra. */
    $this->post('/accedi', ['email' => 'conto.unico@example.test', 'password' => 'password-conto-unico'])->assertRedirect(route('account.feed'));
});

it('dall app cancella l account, il token smette di valere e lo stesso indirizzo può iscriversi da capo', function (): void {
    $user = User::factory()->create(['email' => 'via.dall.app@example.test', 'password' => Hash::make('password-via-dall-app')]);
    $token = iscrizioneApiAccedi('via.dall.app@example.test', 'password-via-dall-app', 'Telefono');

    iscrizioneApiNuovaRichiesta();
    $this->withToken($token)->deleteJson('/api/v1/me', ['password' => 'sbagliata', 'confirmation' => __('account.profile.delete_keyword')])->assertStatus(422);
    expect($user->fresh()?->trashed())->toBeFalse();

    iscrizioneApiNuovaRichiesta();
    $this->withToken($token)->deleteJson('/api/v1/me', ['password' => 'password-via-dall-app', 'confirmation' => __('account.profile.delete_keyword')])->assertOk()
        ->assertJsonPath('data.message', __('account.api.deleted'));

    iscrizioneApiNuovaRichiesta();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

    iscrizioneApiNuovaRichiesta();
    $this->postJson('/api/v1/auth/login', ['email' => 'via.dall.app@example.test', 'password' => 'password-via-dall-app'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    iscrizioneApiNuovaRichiesta();
    $this->postJson('/api/v1/auth/register', ['email' => 'via.dall.app@example.test', 'password' => 'password-di-ritorno-app'])->assertStatus(202);
    $nuovo = User::query()->where('email', 'via.dall.app@example.test')->sole();
    expect($nuovo->id)->not->toBe($user->id);
    Notification::assertSentTo($nuovo, RegistrationLink::class);
});

it('dall app chiede la password nuova con l indirizzo in maiuscolo e la imposta, senza dire quali indirizzi esistono', function (): void {
    $user = User::factory()->create(['email' => 'smemorata.app@example.test', 'password' => Hash::make('password-scordata-app')]);

    $nota = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'SMEMORATA.app@example.test'])->assertOk();
    iscrizioneApiNuovaRichiesta();
    $ignota = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nessuno.app@example.test'])->assertOk();
    expect($ignota->json())->toBe($nota->json());
    Notification::assertSentToTimes($user, ResetPasswordLink::class, 1);
    Notification::assertCount(1);

    $notifica = Notification::sent($user, ResetPasswordLink::class)->first();
    $token = (fn (): string => $this->token)->call($notifica);

    iscrizioneApiNuovaRichiesta();
    $this->postJson('/api/v1/auth/password/reset', [
        'token' => 'gettone-sbagliato',
        'email' => 'smemorata.app@example.test',
        'password' => 'password-ritrovata-app',
        'password_confirmation' => 'password-ritrovata-app',
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['fields' => ['token']]]);

    iscrizioneApiNuovaRichiesta();
    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'email' => 'Smemorata.App@example.test',
        'password' => 'password-ritrovata-app',
        'password_confirmation' => 'password-ritrovata-app',
    ])->assertOk();

    iscrizioneApiAccedi('smemorata.app@example.test', 'password-ritrovata-app', 'Telefono');
});
