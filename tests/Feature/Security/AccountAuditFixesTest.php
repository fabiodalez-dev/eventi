<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Models\Device;
use App\Models\User;
use App\Models\WhatsappChallenge;
use App\Services\Community\WhatsappVerification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Correzioni dell'audit di novembre sugli account: i canali push dopo una
 * reimpostazione della password, il tetto dei codici WhatsApp per numero e il
 * limite delle email in uscita con IPv6.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('revoca i dispositivi push di chi è stato buttato fuori quando si reimposta la password', function (): void {
    config(['webpush.vapid.public_key' => 'chiave-pubblica', 'webpush.vapid.private_key' => 'chiave-privata']);
    $victim = User::factory()->create(['email' => 'vittima@example.test']);
    $stolen = $victim->createToken('telefono-intruso', ['*'], now()->addDays(90))->plainTextToken;
    $this->withToken($stolen)->postJson('/api/v1/me/devices', ['platform' => 'android', 'installation_id' => 'intruso-installazione-0001', 'push_token' => 'TOKEN_FCM_INTRUSO'])->assertCreated();
    $this->flushHeaders();
    Auth::forgetGuards();
    $this->actingAs($victim, 'web')->postJson('/notifiche/push', [
        'endpoint' => 'https://8.8.8.8/push/INTRUSO',
        'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
    ])->assertCreated();
    expect($victim->routeNotificationForFcm())->not->toBeEmpty();

    Auth::forgetGuards();
    $this->app['auth']->guard('web')->logout();
    $this->post('/reimposta-password', [
        'token' => Password::broker()->createToken($victim),
        'email' => 'vittima@example.test',
        'password' => 'Nuova-Password-Lunga-2026!',
        'password_confirmation' => 'Nuova-Password-Lunga-2026!',
    ])->assertRedirect();

    $victim = $victim->fresh();
    expect(Device::query()->where('user_id', $victim->id)->whereNull('revoked_at')->count())->toBe(0)
        ->and($victim->routeNotificationForFcm())->toBeEmpty()
        ->and($victim->routeNotificationForWebPush())->toBeEmpty();
});

it('non azzera il tetto dei codici WhatsApp per numero quando gli account si cancellano', function (): void {
    config(['community.enabled' => true, 'community.whatsapp_enabled' => true, 'community.kapso_key' => 'chiave',
        'community.phone_number_id' => '999', 'community.phone_hash_key' => 'chiave-impronta']);
    Http::preventStrayRequests();
    Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.x']]])]);
    $service = app(WhatsappVerification::class);
    $send = function (User $user, string $ip) use ($service): string {
        try {
            $service->request($user, '+393339876543', $ip);

            return 'inviato';
        } catch (ValidationException) {
            return 'rifiutato';
        }
    };
    $senders = [];
    for ($i = 1; $i <= 3; $i++) {
        $this->travel(3)->minutes();
        $senders[] = $user = User::factory()->create(['email_verified_at' => now()]);
        expect($send($user, '203.0.113.'.$i))->toBe('inviato');
    }
    foreach ($senders as $user) {
        app(DeleteAccount::class)($user);
    }

    expect(WhatsappChallenge::query()->where('phone_hash', $service->fingerprint('+393339876543'))->count())->toBe(3)
        ->and(WhatsappChallenge::query()->where('phone_hash', $service->fingerprint('+393339876543'))->where('code_hash', '!=', '')->count())->toBe(0);
    $this->travel(3)->minutes();
    expect($send(User::factory()->create(['email_verified_at' => now()]), '203.0.113.50'))->toBe('rifiutato');
});

it('conta le email in uscita per blocco /64, non per singolo indirizzo IPv6', function (): void {
    Notification::fake();
    $statuses = [];
    for ($i = 1; $i <= 12; $i++) {
        $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:aa:2::'.dechex(0x1000 + $i)])
            ->postJson('/api/v1/auth/register', ['email' => "ipv6-{$i}@example.test", 'password' => 'Correct-Horse-Battery-9', 'password_confirmation' => 'Correct-Horse-Battery-9'])
            ->getStatusCode();
    }

    expect(array_count_values($statuses)[429] ?? 0)->toBe(2);
});
