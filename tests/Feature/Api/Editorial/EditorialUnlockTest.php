<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\EditorialUnlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * Sblocco e password recente (decisioni 2-4): `POST editorial/unlock`, il
 * 423 `EDITORIAL_LOCKED` dopo 12 ore, il 423 `PASSWORD_REQUIRED` dopo 15
 * minuti sulle azioni pericolose, il registro di sicurezza con `canale: app`.
 * Le rotte di prova (`EditorialSession::registerProbeRoutes`) hanno lo stesso
 * impianto di quelle dei pacchetti.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    EditorialSession::registerProbeRoutes();
});

it('sblocca con la password giusta per 12 ore e 15 minuti, e lo scrive nel registro', function (): void {
    CarbonImmutable::setTestNow('2026-11-26 10:00:00');
    $token = EditorialSession::token($this->s->moderator, unlocked: false);

    EditorialSession::as($this, $token)->getJson('/api/v1/editorial/_test/locked')->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');

    EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'password'])->assertOk()
        ->assertJsonPath('data.unlocked', true)
        ->assertJsonPath('data.unlocked_until', CarbonImmutable::parse('2026-11-26 22:00:00')->toIso8601String())
        ->assertJsonPath('data.password_confirmed_until', CarbonImmutable::parse('2026-11-26 10:15:00')->toIso8601String());

    EditorialSession::as($this, $token)->getJson('/api/v1/editorial/_test/locked')->assertOk();
    EditorialSession::as($this, $token)->postJson('/api/v1/editorial/_test/destroy')->assertOk();
    EditorialSession::as($this, $token)->getJson('/api/v1/editorial/desk')->assertOk()->assertJsonPath('data.unlock.unlocked', true);

    $log = Activity::query()->where('log_name', 'sicurezza')->where('description', 'redazione_sbloccata')->sole();
    expect($log->getProperty('canale'))->toBe('app')
        ->and($log->getProperty('ruolo'))->toBe('moderator')
        ->and($log->subject_id)->toBe($this->s->moderator->id)
        ->and(EditorialUnlock::query()->count())->toBe(1);
    CarbonImmutable::setTestNow();
});

it('rifiuta una password sbagliata con 422 e la scrive nel registro', function (): void {
    $token = EditorialSession::token($this->s->admin, unlocked: false);

    EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'sbagliata'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['fields' => ['password']]]);
    EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', [])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['password']]]);

    expect(EditorialUnlock::query()->count())->toBe(0)
        ->and(Activity::query()->where('description', 'redazione_sblocco_fallito')->sole()->getProperty('canale'))->toBe('app');
    EditorialSession::as($this, $token)->getJson('/api/v1/editorial/_test/locked')->assertStatus(423);
});

it('limita i tentativi di sblocco a sei ogni quindici minuti', function (): void {
    $token = EditorialSession::token($this->s->admin, unlocked: false);

    foreach (range(1, 6) as $attempt) {
        EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'sbagliata-'.$attempt])->assertUnprocessable();
    }
    EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'password'])->assertTooManyRequests();
});

it('blocca di nuovo dopo 12 ore, e chiede la password dopo 15 minuti solo alle azioni pericolose', function (): void {
    $fresh = EditorialSession::token($this->s->admin);
    $stale = EditorialSession::token($this->s->admin, passwordMinutesAgo: 16);
    $expired = EditorialSession::token($this->s->admin, unlockedHoursAgo: 12, passwordMinutesAgo: 1);

    EditorialSession::as($this, $fresh)->postJson('/api/v1/editorial/_test/destroy')->assertOk();

    EditorialSession::as($this, $stale)->getJson('/api/v1/editorial/_test/locked')->assertOk();
    EditorialSession::as($this, $stale)->postJson('/api/v1/editorial/_test/destroy')->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    // Riscrivere la password rinnova la finestra e ripete l'azione.
    EditorialSession::as($this, $stale)->postJson('/api/v1/editorial/unlock', ['password' => 'password'])->assertOk();
    EditorialSession::as($this, $stale)->postJson('/api/v1/editorial/_test/destroy')->assertOk();

    EditorialSession::as($this, $expired)->getJson('/api/v1/editorial/_test/locked')->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
    EditorialSession::as($this, $expired)->postJson('/api/v1/editorial/_test/destroy')->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
});

it('lega lo sblocco al token: un altro token della stessa persona resta bloccato, e uscire lo cancella', function (): void {
    $unlocked = EditorialSession::token($this->superAdmin);
    $other = EditorialSession::token($this->superAdmin, unlocked: false);

    EditorialSession::as($this, $unlocked)->getJson('/api/v1/editorial/_test/locked')->assertOk();
    EditorialSession::as($this, $other)->getJson('/api/v1/editorial/_test/locked')->assertStatus(423);

    PersonalAccessToken::query()->whereKey(EditorialUnlock::query()->value('token_id'))->delete();
    expect(EditorialUnlock::query()->count())->toBe(0);
});

it('non sblocca chi entra senza un token salvato, e tiene fuori chi non è della redazione', function (): void {
    // Un accesso senza token dell'app (`Sanctum::actingAs`): niente a cui legare lo sblocco.
    Sanctum::actingAs($this->s->admin);
    $this->postJson('/api/v1/editorial/unlock', ['password' => 'password'])->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
    $this->getJson('/api/v1/editorial/_test/locked')->assertStatus(423);

    // Referente e utente comune: 404 anche con un token sbloccato e la password giusta.
    foreach ([$this->s->ownerA, $this->s->plainUser] as $outsider) {
        $token = EditorialSession::token($outsider);
        EditorialSession::as($this, $token)->getJson('/api/v1/editorial/_test/locked')->assertNotFound();
        EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'password'])->assertNotFound();
    }
});

it('scrive canale app sulle righe del registro nate da una richiesta della redazione, e non su quelle del pannello', function (): void {
    // Prima di ogni richiesta della redazione (comandi, pannello): nessun canale.
    activity('sicurezza')->log('fuori_dalla_redazione');
    expect(Activity::query()->latest('id')->first()?->getProperty('canale'))->toBeNull();

    $token = EditorialSession::token($this->s->admin);
    EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'password'])->assertOk();

    expect(Activity::query()->latest('id')->first()?->getProperty('canale'))->toBe('app');
});
