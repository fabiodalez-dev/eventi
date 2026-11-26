<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\AccountAlreadyRegistered;
use App\Notifications\RegistrationLink;
use App\Notifications\VenueApplicationReceived;
use App\Services\Account\MobileMagicLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * Q2 (W-AP-02) — registrare o rivendicare da ospite nell'app.
 *
 * Sul sito l'ospite scrive la password nel modulo e la richiesta aspetta la
 * conferma dell'email sul server. L'app tiene la bozza sul telefono e usa le
 * rotte che ha già: le letture del modulo (pubbliche, come le pagine del
 * sito), `POST /auth/register` (sempre la stessa risposta, D64) e, quando
 * arriva la sessione, le rotte dei collegati. Lo scambio del collegamento
 * dice se chiude una registrazione di quella installazione: solo allora
 * l'app manda la richiesta da sola.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    Storage::fake('local');
    $this->city = testCity();
});

/** La sfida PKCE di un verificatore, come la calcola l'app (`MagicLinkProof`). */
function sfidaDi(string $verificatore): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verificatore, true)), '+/', '-_'), '=');
}

/** Il gettone del collegamento per l'app (`/app/auth/magic?token=…`). */
function gettoneDi(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

/** @return array<string, mixed> */
function registrazioneDaOspite(string $email, string $verificatore): array
{
    return [
        'name' => 'Marta Rossi',
        'first_name' => 'Marta Rossi',
        'email' => $email,
        'password' => 'Password-Lunga-2026',
        'password_confirmation' => 'Password-Lunga-2026',
        'device_name' => 'Telefono di Marta',
        'code_challenge' => sfidaDi($verificatore),
    ];
}

it('apre all’ospite le letture del modulo, senza indirizzo dell’account', function (): void {
    $libero = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria del Ponte']);

    $options = $this->getJson('/api/v1/me/venue-applications/options')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($options->json('data.account_email'))->toBeNull()
        ->and($options->json('data.municipality'))->toBe($this->city->name)
        ->and($options->json('data.types'))->not->toBeEmpty();

    $found = $this->getJson('/api/v1/venue-applications/lookup?q=ponte')->assertOk()->json('data');
    expect(collect($found)->pluck('slug')->all())->toBe([$libero->slug])
        ->and($found[0]['claimable'])->toBeTrue();

    $this->getJson('/api/v1/venues/'.$libero->slug.'/claim')->assertOk()->assertJsonPath('data.name', 'Osteria del Ponte');

    /* Un locale con un referente resta non rivendicabile anche da ospite. */
    $gestito = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $gestito->members()->attach(User::factory()->create(), ['role' => 'owner', 'accepted_at' => now()]);
    $this->getJson('/api/v1/venues/'.$gestito->slug.'/claim')->assertNotFound();
});

it('continua a chiedere l’accesso per mandare, confermare o eliminare', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);

    $this->postJson('/api/v1/me/venue-applications', ['venue_name' => 'Circolo', 'address' => 'Via Roma 1', 'contact_name' => 'Marta'])->assertUnauthorized();
    $this->postJson('/api/v1/venues/'.$venue->slug.'/claim', ['contact_name' => 'Marta', 'message' => 'Sono la titolare dal 2019.'])->assertUnauthorized();
    $this->postJson('/api/v1/me/organizer-applications', ['venue_name' => 'Collettivo', 'contact_name' => 'Marta'])->assertUnauthorized();
    $this->postJson('/api/v1/me/venue-applications/1/confirm')->assertUnauthorized();
    $this->deleteJson('/api/v1/me/venue-applications/1')->assertUnauthorized();
    expect(VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)->count())->toBe(0);
});

it('dall’account nuovo dell’app la richiesta parte dopo la conferma ed entra subito in coda', function (): void {
    $verificatore = str_repeat('a', 43);
    $this->postJson('/api/v1/auth/register', registrazioneDaOspite('nuova@esempio.test', $verificatore))
        ->assertStatus(202)->assertJsonMissingPath('data.token');

    /* Prima della conferma il server non ha nessuna richiesta: la bozza sta sul telefono. */
    expect(VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)->count())->toBe(0);

    $user = User::query()->where('email', 'nuova@esempio.test')->sole();
    $link = Notification::sent($user, RegistrationLink::class)->first();
    $session = $this->postJson('/api/v1/auth/magic-link/exchange', [
        'token' => gettoneDi($link->url), 'code_verifier' => $verificatore, 'device_name' => 'Telefono di Marta',
    ])->assertOk()->assertJsonPath('data.registration', true)->assertJsonPath('data.user.email_verified', true);

    $this->app['auth']->forgetGuards();
    $this->withToken((string) $session->json('data.token'))
        ->postJson('/api/v1/me/venue-applications', [
            'venue_name' => 'Circolo del Ponte', 'address' => 'Via Roma 1', 'contact_name' => 'Marta Rossi', 'message' => 'Musica dal vivo.',
        ])->assertCreated()->assertJsonPath('data.state', 'queued');

    $application = VenueApplication::query()->sole();
    expect($application->user_id)->toBe($user->id)
        ->and($application->contact_email)->toBe('nuova@esempio.test')
        ->and($application->email_confirmed_at)->not->toBeNull();
    Notification::assertSentTo($user, VenueApplicationReceived::class);
});

it('per un indirizzo già registrato risponde uguale e lo scambio non è una registrazione', function (): void {
    $esistente = User::factory()->create(['email' => 'gia@esempio.test']);
    $nuovo = $this->postJson('/api/v1/auth/register', registrazioneDaOspite('altra@esempio.test', str_repeat('b', 43)))->assertStatus(202);

    $verificatore = str_repeat('c', 43);
    $gia = $this->postJson('/api/v1/auth/register', registrazioneDaOspite('gia@esempio.test', $verificatore))->assertStatus(202);

    /* D64: stessa risposta, che l'indirizzo sia nuovo o già usato. */
    expect($gia->json())->toBe($nuovo->json());

    $link = Notification::sent($esistente, AccountAlreadyRegistered::class)->first();
    $this->postJson('/api/v1/auth/magic-link/exchange', [
        'token' => gettoneDi($link->url), 'code_verifier' => $verificatore, 'device_name' => 'Telefono',
    ])->assertOk()->assertJsonPath('data.registration', false)->assertJsonPath('data.user.email', 'gia@esempio.test');
});

it('lo scambio di un collegamento di accesso ordinario non è una registrazione', function (): void {
    $user = User::factory()->create(['email' => 'marta@esempio.test']);
    $verificatore = str_repeat('d', 43);

    /* Lo stesso collegamento che spedisce `POST /auth/magic-link`. */
    $url = app(MobileMagicLink::class)->issue($user, sfidaDi($verificatore));

    $this->postJson('/api/v1/auth/magic-link/exchange', [
        'token' => gettoneDi($url), 'code_verifier' => $verificatore, 'device_name' => 'Telefono',
    ])->assertOk()->assertJsonPath('data.registration', false);
});
