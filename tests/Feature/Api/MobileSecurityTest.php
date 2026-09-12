<?php

declare(strict_types=1);

use App\Enums\DevicePlatform;
use App\Models\Device;
use App\Models\MobileAuthChallenge;
use App\Models\SavedEvent;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');

    $this->owner = User::factory()->create(['password' => Hash::make('password-sicura-owner')]);
    $this->other = User::factory()->create(['password' => Hash::make('password-sicura-other')]);
    $this->ownerToken = $this->owner->createToken('Owner Android')->plainTextToken;
    $this->otherToken = $this->other->createToken('Other Android')->plainTextToken;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('isola wishlist e stato is_saved fra account diversi', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    $this->withToken($this->ownerToken)
        ->postJson('/api/v1/me/saved', ['occurrence_id' => $occurrence->getKey()])
        ->assertCreated();

    $this->app->make('auth')->forgetGuards();
    $this->withToken($this->otherToken)
        ->getJson('/api/v1/me/saved')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withToken($this->otherToken)
        ->deleteJson('/api/v1/me/saved/'.$occurrence->getKey())
        ->assertNotFound();

    expect(SavedEvent::query()->where('user_id', $this->owner->getKey())->count())->toBe(1);

    $this->withToken($this->otherToken)
        ->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonPath('data.0.is_saved', false)
        ->assertHeader('Vary', 'Authorization, X-Installation-ID');

    $this->app->make('auth')->forgetGuards();
    $this->withToken($this->ownerToken)
        ->getJson('/api/v1/events')
        ->assertOk()
        ->assertJsonPath('data.0.is_saved', true);
});

it('rende idempotenti i salvataggi senza condividere risposte fra utenti', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $otherOccurrence = occurrenceAt($this->city, $this->category, '2026-09-13 19:00:00');
    $key = 'wishlist-key-123456789';

    $this->withToken($this->ownerToken)->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/me/saved', ['occurrence_id' => $occurrence->getKey()])
        ->assertCreated();

    $this->app->make('auth')->forgetGuards();
    $this->withToken($this->otherToken)->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/me/saved', ['occurrence_id' => $occurrence->getKey()])
        ->assertCreated();

    expect(SavedEvent::query()->where('occurrence_id', $occurrence->getKey())->count())->toBe(2);

    $this->app->make('auth')->forgetGuards();
    $this->withToken($this->ownerToken)->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/me/saved', ['occurrence_id' => $occurrence->getKey()])
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    $this->withToken($this->ownerToken)->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/me/saved', ['occurrence_id' => $otherOccurrence->getKey()])
        ->assertConflict()
        ->assertJsonPath('error.code', 'CONFLICT');
});

it('trasferisce un token push a un solo account e revoca la vecchia sessione', function (): void {
    $payload = [
        'platform' => DevicePlatform::Android->value,
        'installation_id' => 'android-installation-0001',
        'push_token' => str_repeat('fcm-token-', 20),
        'app_version' => '1.0.0',
    ];

    $deviceId = $this->withToken($this->ownerToken)
        ->postJson('/api/v1/me/devices', $payload)
        ->assertCreated()
        ->json('data.id');

    $this->app->make('auth')->forgetGuards();
    $this->withToken($this->otherToken)
        ->postJson('/api/v1/me/devices', [...$payload, 'installation_id' => 'android-installation-0002'])
        ->assertCreated()
        ->assertJsonPath('data.id', $deviceId);

    $device = Device::query()->findOrFail($deviceId);

    expect((int) $device->user_id)->toBe((int) $this->other->getKey())
        ->and(Device::query()->where('token_hash', hash('sha256', $payload['push_token']))->count())->toBe(1)
        ->and($this->owner->tokens()->count())->toBe(0)
        ->and($this->other->tokens()->first()?->device_id)->toBe($deviceId);

    $this->app->make('auth')->forgetGuards();
    $this->withToken($this->ownerToken)->getJson('/api/v1/me')->assertUnauthorized();
});

it('non permette di elencare o revocare sessioni di un altro account', function (): void {
    $ownerSecond = $this->owner->createToken('Owner Tablet')->accessToken;
    $otherSession = $this->other->tokens()->firstOrFail();

    $sessions = $this->withToken($this->ownerToken)
        ->getJson('/api/v1/me/sessions')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(collect($sessions->json('data'))->firstWhere('current', true)['name'] ?? null)->toBe('Owner Android');

    $this->withToken($this->ownerToken)
        ->deleteJson('/api/v1/me/sessions/'.$otherSession->getKey())
        ->assertNotFound();

    $this->withToken($this->ownerToken)
        ->deleteJson('/api/v1/me/sessions/'.$ownerSecond->getKey())
        ->assertOk();

    expect($this->other->tokens()->whereKey($otherSession->getKey())->exists())->toBeTrue();
});

it('non permette di leggere le notifiche di un altro account', function (): void {
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'event_reminder',
        'notifiable_type' => 'user',
        'notifiable_id' => $this->owner->getKey(),
        'data' => json_encode(['title' => 'Promemoria', 'url' => '/evento'], JSON_THROW_ON_ERROR),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken($this->otherToken)
        ->patchJson('/api/v1/me/notifications/'.$id.'/read')
        ->assertNotFound();

    expect(DB::table('notifications')->where('id', $id)->value('read_at'))->toBeNull();

    $this->app->make('auth')->forgetGuards();
    $this->withToken($this->ownerToken)
        ->patchJson('/api/v1/me/notifications/'.$id.'/read')
        ->assertOk()
        ->assertJsonPath('data.read', true);
});

it('consuma una sola volta il challenge del magic link mobile', function (): void {
    $raw = str_repeat('a', 64);

    MobileAuthChallenge::query()->create([
        'user_id' => $this->owner->getKey(),
        'token_hash' => hash('sha256', $raw),
        'password_fingerprint' => MagicLoginLink::fingerprint($this->owner),
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 43), true)), '+/', '-_'), '='),
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->postJson('/api/v1/auth/magic-link/exchange', [
        'code_verifier' => str_repeat('v', 43), 'token' => $raw,
        'device_name' => 'Pixel 9',
    ])
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.id', (int) $this->owner->getKey());

    $this->postJson('/api/v1/auth/magic-link/exchange', ['code_verifier' => str_repeat('v', 43), 'token' => $raw])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'INVALID_TOKEN');
});

it('richiede password e conferma esplicita prima di cancellare un account', function (): void {
    $this->withToken($this->ownerToken)
        ->deleteJson('/api/v1/me', ['confirmation' => 'CANCELLA', 'password' => 'sbagliata'])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['password']]]);

    $this->withToken($this->ownerToken)
        ->deleteJson('/api/v1/me', ['confirmation' => 'cancella', 'password' => 'password-sicura-owner'])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['confirmation']]]);

    $this->withToken($this->ownerToken)
        ->deleteJson('/api/v1/me', ['confirmation' => 'CANCELLA', 'password' => 'password-sicura-owner'])
        ->assertOk();

    expect($this->owner->fresh()?->deleted_at)->not->toBeNull()
        ->and($this->owner->tokens()->count())->toBe(0);
});
