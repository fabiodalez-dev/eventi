<?php

declare(strict_types=1);

use App\Enums\DevicePlatform;
use App\Enums\FollowableType;
use App\Enums\UserRole;
use App\Models\Device;
use App\Models\Follow;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

/**
 * Gli endpoint dell'area personale di §15.8.
 *
 * Valgono le stesse regole del resto dell'API (D28): risposte `{data, meta}`,
 * errori `{error{code,message,fields}}`, paginazione a cursore, e nessuna di
 * queste risposte in cache condivisa — parlano di una persona sola.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->city = testCity();
    $this->category = testCategory();

    freezeLocal($this->city, '2026-09-10 18:00');

    $this->user = User::factory()->create(['name' => 'Giulia', 'email' => 'giulia@example.test']);
    $this->token = $this->user->createToken('Telefono')->plainTextToken;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('chiede un token per ogni indirizzo dell area personale', function (): void {
    foreach ([
        ['get', '/api/v1/me'],
        ['get', '/api/v1/me/saved'],
        ['get', '/api/v1/me/follows'],
        ['get', '/api/v1/me/feed'],
        ['get', '/api/v1/me/notifications'],
        ['get', '/api/v1/me/notification-preferences'],
        ['get', '/api/v1/me/export'],
    ] as [$verb, $url]) {
        $this->json(mb_strtoupper($verb), $url)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
});

it('restituisce e aggiorna il profilo minimo', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'giulia@example.test')
        ->assertJsonPath('data.name', 'Giulia')
        ->assertJsonPath('data.role_label', UserRole::User->label())
        ->assertJsonPath('data.timezone', 'Europe/Rome')
        ->assertJsonPath('data.marketing_opt_in', false);

    $this->withToken($this->token)->patchJson('/api/v1/me', [
        'name' => 'Giulia Rossi',
        'locale' => 'en',
        'marketing_opt_in' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Giulia Rossi')
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonPath('data.marketing_opt_in', true);

    /* Il consenso marketing è una data, non un booleano (§15.9). */
    expect($this->user->fresh()?->marketing_opt_in_at)->not->toBeNull();

    /* Il fuso deve essere un fuso vero. */
    $this->withToken($this->token)->patchJson('/api/v1/me', ['timezone' => 'Marte/Olympus'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('identifies only the authenticated account role without exposing permissions', function (UserRole $role): void {
    $this->user->assignRole(Role::findOrCreate($role->value, 'web'));
    $other = User::factory()->create();
    $other->assignRole(Role::findOrCreate(UserRole::SuperAdmin->value, 'web'));
    $this->withToken($this->token)->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.role_label', $role->label())
        ->assertJsonPath('data.email', $this->user->email)
        ->assertJsonMissingPath('data.roles')
        ->assertJsonMissingPath('data.permissions');
})->with(UserRole::cases());

it('does not let a profile edit change its role label or permissions', function (): void {
    $this->withToken($this->token)->patchJson('/api/v1/me', ['name' => 'Giulia', 'role_label' => 'Amministratore', 'role' => 'admin'])
        ->assertOk()->assertJsonPath('data.role_label', UserRole::User->label());
    expect($this->user->fresh()->hasRole(UserRole::Admin))->toBeFalse();
});

it('espone le preferenze di notifica con i valori predefiniti di §15.4', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/me/notification-preferences')
        ->assertOk()
        ->assertJsonPath('data.reminders', true)
        ->assertJsonPath('data.reminder_hours', [24, 3])
        ->assertJsonPath('data.venue_digest', true)
        ->assertJsonPath('data.daily_digest', false)
        /* Gli annullamenti non hanno un interruttore: sono dichiarati e basta. */
        ->assertJsonPath('data.cancellations', true);

    $this->withToken($this->token)->patchJson('/api/v1/me/notification-preferences', [
        'daily_digest' => true,
        'reminder_hours' => [48, 2],
    ])
        ->assertOk()
        ->assertJsonPath('data.daily_digest', true)
        ->assertJsonPath('data.reminder_hours', [48, 2])
        ->assertJsonPath('data.cancellations', true);

    /* Un'ora fuori scala è un errore, non un valore da correggere in silenzio. */
    $this->withToken($this->token)->patchJson('/api/v1/me/notification-preferences', ['reminder_hours' => [0]])
        ->assertStatus(422);
});

it('salva una data, la elenca e la toglie', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    $this->withToken($this->token)->postJson('/api/v1/me/saved', ['occurrence_id' => $occurrence->getKey()])
        ->assertCreated()
        ->assertJsonPath('data.occurrence_id', (int) $occurrence->getKey());

    $this->withToken($this->token)->getJson('/api/v1/me/saved')
        ->assertOk()
        ->assertJsonPath('data.0.occurrence_id', (int) $occurrence->getKey())
        ->assertJsonPath('data.0.is_saved', true)
        ->assertJsonPath('meta.has_more', false);

    $this->withToken($this->token)->deleteJson('/api/v1/me/saved/'.$occurrence->getKey())->assertOk();

    $this->withToken($this->token)->getJson('/api/v1/me/saved')->assertOk()->assertJsonCount(0, 'data');

    /* Togliere due volte è un 404: la seconda chiamata non ha più niente da
       togliere, e dirlo è più onesto di un 200 senza effetto. */
    $this->withToken($this->token)->deleteJson('/api/v1/me/saved/'.$occurrence->getKey())->assertNotFound();
});

it('elenca l archivio dei salvataggi solo su richiesta', function (): void {
    $futura = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $passata = occurrenceAt($this->city, $this->category, '2026-08-20 19:00:00');

    SavedEvent::query()->create(['user_id' => $this->user->getKey(), 'occurrence_id' => $futura->getKey()]);
    SavedEvent::query()->create(['user_id' => $this->user->getKey(), 'occurrence_id' => $passata->getKey()]);

    /* `upcoming=1` è il valore predefinito: l'agenda comincia da adesso. */
    $this->withToken($this->token)->getJson('/api/v1/me/saved')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.occurrence_id', (int) $futura->getKey());

    /* Con l'archivio l'ordine si ribalta: prima la data più recente. */
    $this->withToken($this->token)->getJson('/api/v1/me/saved?upcoming=0')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.occurrence_id', (int) $futura->getKey());
});

it('segue e smette di seguire, dicendo che cosa segue', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Circolo Nuovo']);

    $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'venue', 'id' => $venue->getKey()])
        ->assertCreated()
        ->assertJsonPath('data.type', 'venue')
        ->assertJsonPath('data.name', 'Circolo Nuovo')
        ->assertJsonPath('data.notify', true);

    $this->withToken($this->token)->getJson('/api/v1/me/follows')
        ->assertOk()
        ->assertJsonPath('data.0.id', (int) $venue->getKey());

    $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'venue', 'id' => 999999])
        ->assertNotFound();

    $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'pianeta', 'id' => 1])
        ->assertStatus(422);

    $this->withToken($this->token)->deleteJson('/api/v1/me/follows/venue/'.$venue->getKey())->assertOk();
    $this->withToken($this->token)->deleteJson('/api/v1/me/follows/venue/'.$venue->getKey())->assertNotFound();
});

it('consegna il feed di ciò che si segue e l avvio guidato a chi non segue niente', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Circolo del feed']);

    occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00', venue: $venue, event: ['title' => 'Serata nel feed']);

    /* Senza follow: nessuna data, ma **mai** una risposta vuota e basta. */
    $vuoto = $this->withToken($this->token)->getJson('/api/v1/me/feed')->assertOk()->assertJsonCount(0, 'data');

    expect($vuoto->json('meta.onboarding.venues'))->not->toBeEmpty()
        ->and($vuoto->json('meta.onboarding.categories'))->not->toBeEmpty();

    Follow::query()->create([
        'user_id' => $this->user->getKey(),
        'followable_type' => FollowableType::Venue->value,
        'followable_id' => $venue->getKey(),
    ]);

    $this->withToken($this->token)->getJson('/api/v1/me/feed')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Serata nel feed')
        ->assertJsonPath('data.0.is_saved', false)
        ->assertJsonPath('meta.onboarding', null);
});

it('registra un dispositivo, lo riconosce alla seconda registrazione e lo revoca', function (): void {
    $primo = $this->withToken($this->token)->postJson('/api/v1/me/devices', [
        'platform' => DevicePlatform::Web->value,
        'endpoint' => 'https://push.example/abc',
        'app_version' => '1.0.0',
    ])->assertCreated();

    /* Il token non esce mai dalla risposta: è la chiave con cui si spinge una
       notifica verso quel dispositivo. */
    expect($primo->json('data'))->not->toHaveKey('endpoint')
        ->and($primo->json('data'))->not->toHaveKey('push_token');

    $this->withToken($this->token)->postJson('/api/v1/me/devices', [
        'platform' => DevicePlatform::Web->value,
        'endpoint' => 'https://push.example/abc',
        'app_version' => '1.1.0',
    ])->assertCreated();

    expect($this->user->devices()->count())->toBe(1)
        ->and($this->user->devices()->first()?->app_version)->toBe('1.1.0');

    $id = (int) $primo->json('data.id');

    $this->withToken($this->token)->deleteJson('/api/v1/me/devices/'.$id)->assertOk();

    /* Revocato, non cancellato: `revoked_at` è ciò che fa ripiegare l'invio
       sull'email al prossimo giro (§15.6). */
    expect(Device::query()->whereKey($id)->first()?->revoked_at)->not->toBeNull();

    /* Un dispositivo di un altro non si tocca. */
    $altrui = Device::factory()->create();

    $this->withToken($this->token)->deleteJson('/api/v1/me/devices/'.$altrui->getKey())->assertNotFound();
});

it('consegna l archivio delle notifiche e l esportazione dei dati', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    SavedEvent::query()->create(['user_id' => $this->user->getKey(), 'occurrence_id' => $occurrence->getKey()]);

    $this->withToken($this->token)->getJson('/api/v1/me/notifications')
        ->assertOk()
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonCount(0, 'data');

    $export = $this->withToken($this->token)->getJson('/api/v1/me/export')->assertOk();

    expect($export->json('data.profile.email'))->toBe('giulia@example.test')
        ->and($export->json('data.saved_events'))->toHaveCount(1)
        ->and($export->json('data.saved_events.0.occurrence_id'))->toBe((int) $occurrence->getKey())
        /* Nulla di derivato: il punteggio editoriale non è un dato che la
           persona ha dato. */
        ->and($export->json('data'))->not->toHaveKey('editorial_score');
});

it('non mette in cache condivisa le risposte dell area personale', function (): void {
    $risposta = $this->withToken($this->token)->getJson('/api/v1/me/saved')->assertOk();

    expect($risposta->headers->get('Cache-Control'))->not->toContain('public');
});

it('dichiara nella configurazione che salvataggi e follow esistono', function (): void {
    config(['api.features.push' => false]);
    $this->getJson('/api/v1/config')
        ->assertOk()
        ->assertJsonPath('data.features.saved_events', true)
        ->assertJsonPath('data.features.follows', true)
        /* Optional FCM configuration must not depend on the developer's .env. */
        ->assertJsonPath('data.features.push', false);
});
