<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Enums\DevicePlatform;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Device;
use App\Models\NotificationLog;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Notifications\Scheduled\ScheduledMessage;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Il canale push di §15.6 (D54): quando si sceglie, quando non si sceglie, e
 * cosa arriva davvero al servizio push.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');

    /*
     * Le chiavi VAPID non ci sono in fase di test, ed e' voluto: senza,
     * `ChannelSelector` tiene spento il canale. Qui si accendono perche' e'
     * proprio il canale l'oggetto della prova — vedi il test che verifica il
     * comportamento opposto.
     */
    config()->set('webpush.vapid.public_key', 'chiave-pubblica-di-prova');
    config()->set('webpush.vapid.private_key', 'chiave-privata-di-prova');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Porta l'orologio al momento in cui il promemoria delle ventiquattro ore e'
 * dovuto (§15.4) e fa girare il worker.
 */
function inviaIlPromemoria(): void
{
    Carbon::setTestNow(localInstant(test()->city, '2026-09-04 21:00'));

    test()->artisan('notifications:send')->assertSuccessful();
}

/**
 * Un `WebPush` finto al posto di quello vero, che raccoglie cio' che gli viene
 * consegnato. Sostituisce **solo** il trasporto: canale, notifica, scelta del
 * canale e lettura di `devices` restano quelli veri.
 *
 * @param  list<MessageSentReport>  $referti
 * @return Collection<int, array{subscription: Subscription, payload: string|null}>
 */
function trasportoPushFinto(array $referti = []): Collection
{
    $consegne = collect();

    $webPush = Mockery::mock(WebPush::class);

    $webPush->shouldReceive('queueNotification')
        ->andReturnUsing(function (Subscription $subscription, ?string $payload = null) use ($consegne): void {
            $consegne->push(['subscription' => $subscription, 'payload' => $payload]);
        });

    $webPush->shouldReceive('flush')->andReturnUsing(static function () use ($referti): Generator {
        yield from $referti;
    });

    app()->when(WebPushChannel::class)->needs(WebPush::class)->give(static fn (): WebPush => $webPush);

    return $consegne;
}

it('manda il promemoria via push quando il browser è iscritto, e lo scrive nel registro', function (): void {
    $user = User::factory()->create();

    $device = Device::factory()->for($user)->create([
        'endpoint' => 'https://push.example.org/abcdef',
        'keys' => ['p256dh' => 'chiave-del-browser', 'auth' => 'segreto-del-browser'],
    ]);

    app(SaveOccurrences::class)->one($user, $this->occurrence);

    $consegne = trasportoPushFinto();

    inviaIlPromemoria();

    // La consegna e' arrivata al servizio push, con l'iscrizione letta da `devices`.
    expect($consegne)->toHaveCount(1);

    $subscription = $consegne->first()['subscription'];

    expect($subscription->getEndpoint())->toBe($device->endpoint)
        ->and($subscription->getPublicKey())->toBe('chiave-del-browser')
        ->and($subscription->getAuthToken())->toBe('segreto-del-browser');

    // Il contenuto e' quello del messaggio, con il collegamento profondo di §15.4.
    $payload = json_decode((string) $consegne->first()['payload'], true);

    expect($payload)->toBeArray()
        ->and($payload['title'])->not->toBeEmpty()
        ->and($payload['tag'])->toBe('event_reminder')
        ->and($payload['data']['url'])->toContain('/eventi/');

    // §15.6: l'archivio in-app c'e' comunque, qualunque sia il canale.
    expect($user->notifications()->count())->toBe(1);

    // E il registro dice «push», non «email»: e' il punto in cui si va a
    // guardare quando qualcuno sostiene di non aver ricevuto nulla.
    expect(NotificationLog::query()->first()?->channel)->toBe(NotificationChannel::Push)
        ->and(ScheduledNotification::query()->ofStatus(NotificationStatus::Sent)->first()?->channel)
        ->toBe(NotificationChannel::Push);
});

it('resta sull email quando non c è nessun browser iscritto', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    app(SaveOccurrences::class)->one($user, $this->occurrence);

    inviaIlPromemoria();

    Notification::assertSentTo($user, ScheduledMessage::class, function (ScheduledMessage $notification) use ($user): bool {
        return $notification->channel === NotificationChannel::Mail
            && $notification->via($user) === ['mail', 'database'];
    });

    expect(NotificationLog::query()->first()?->channel)->toBe(NotificationChannel::Mail);
});

it('torna all email quando il dispositivo non si fa vedere da oltre trenta giorni', function (): void {
    // §15.6: «push su device attivo negli ultimi 30 giorni».
    Notification::fake();

    $user = User::factory()->create();
    Device::factory()->for($user)->dormant()->create();

    app(SaveOccurrences::class)->one($user, $this->occurrence);

    inviaIlPromemoria();

    expect(NotificationLog::query()->first()?->channel)->toBe(NotificationChannel::Mail);
});

it('torna all email quando il dispositivo è stato revocato', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    Device::factory()->for($user)->revoked()->create();

    app(SaveOccurrences::class)->one($user, $this->occurrence);

    inviaIlPromemoria();

    expect(NotificationLog::query()->first()?->channel)->toBe(NotificationChannel::Mail);
});

it('non usa il canale push di un altro utente', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    Device::factory()->create(); // di qualcun altro

    app(SaveOccurrences::class)->one($user, $this->occurrence);

    inviaIlPromemoria();

    expect(NotificationLog::query()->first()?->channel)->toBe(NotificationChannel::Mail);
});

it('non sceglie il push senza chiavi VAPID, anche con un browser iscritto', function (): void {
    /*
     * Senza chiavi il servizio push rifiuterebbe la richiesta, e il rifiuto
     * arriverebbe dentro il job in coda: un invio fallito invece di un'email
     * consegnata. La condizione e' la stessa che nasconde l'interruttore nella
     * pagina delle preferenze.
     */
    config()->set('webpush.vapid.public_key', null);
    config()->set('webpush.vapid.private_key', null);

    Notification::fake();

    $user = User::factory()->create();
    Device::factory()->for($user)->create();

    app(SaveOccurrences::class)->one($user, $this->occurrence);

    inviaIlPromemoria();

    expect(NotificationLog::query()->first()?->channel)->toBe(NotificationChannel::Mail);
});

it('ignora i dispositivi nativi finche firebase non e configurato', function (): void {
    config()->set('api.features.push', false);
    config()->set('firebase.projects.app.credentials', null);
    Notification::fake();

    $user = User::factory()->create();
    Device::factory()->for($user)->mobile(DevicePlatform::Android)->create();

    app(SaveOccurrences::class)->one($user, $this->occurrence);

    inviaIlPromemoria();

    expect(NotificationLog::query()->first()?->channel)->toBe(NotificationChannel::Mail)
        ->and($user->routeNotificationForWebPush())->toBeEmpty();
});

it('sceglie FCM per un dispositivo Android attivo quando firebase e configurato', function (): void {
    config()->set('webpush.vapid.public_key', null);
    config()->set('webpush.vapid.private_key', null);
    config()->set('api.features.push', true);
    config()->set('firebase.projects.app.credentials', '/tmp/firebase-test.json');

    Notification::fake();

    $user = User::factory()->create();
    $device = Device::factory()->for($user)->mobile(DevicePlatform::Android)->create();

    app(SaveOccurrences::class)->one($user, $this->occurrence);
    inviaIlPromemoria();

    Notification::assertSentTo($user, ScheduledMessage::class, function (ScheduledMessage $notification) use ($device, $user): bool {
        $payload = $notification->toFcm($user)->toArray();

        return $notification->channel === NotificationChannel::Push
            && $notification->via($user) === [FcmChannel::class, 'database']
            && $user->routeNotificationForFcm() === [$device->push_token]
            && ($payload['data']['type'] ?? null) === 'event_reminder'
            && str_contains($payload['data']['url'] ?? '', '/eventi/')
            && ($payload['data']['user_id'] ?? null) === (string) $user->id
            && filled($payload['data']['title'] ?? null)
            && ($payload['android']['priority'] ?? null) === 'high'
            && empty($payload['notification']);
    });

    expect(NotificationLog::query()->first()?->channel)->toBe(NotificationChannel::Push);
});

it('revoca il dispositivo quando il servizio push dice che l iscrizione è scaduta, e al giro dopo scrive per email', function (): void {
    $user = User::factory()->create();

    $device = Device::factory()->for($user)->create([
        'endpoint' => 'https://push.example.org/scaduta',
    ]);

    app(SaveOccurrences::class)->one($user, $this->occurrence);

    // 410 Gone: l'iscrizione non esiste più presso il servizio push.
    trasportoPushFinto([refertoScaduto('https://push.example.org/scaduta')]);

    inviaIlPromemoria();

    expect($device->refresh()->revoked_at)->not->toBeNull()
        ->and(NotificationLog::query()->first()?->channel)->toBe(NotificationChannel::Push);

    // Il secondo invio — quello a tre ore dall'inizio (§15.4) — trova il
    // dispositivo revocato e ripiega sull'email: è il ritorno previsto da §15.6.
    Notification::fake();

    Carbon::setTestNow(localInstant($this->city, '2026-09-05 18:30'));
    $this->artisan('notifications:send')->assertSuccessful();

    expect(NotificationLog::query()->latest('id')->first()?->channel)->toBe(NotificationChannel::Mail);
});

it('non revoca il dispositivo per un guasto passeggero del servizio push', function (): void {
    // Un 503 non dice niente sull'iscrizione: revocarla sposterebbe su email
    // qualcuno che ha solo avuto sfortuna.
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();

    event(new NotificationFailed(
        refertoNonScaduto((string) $device->endpoint),
        new WebPushSubscription,
        new WebPushMessage,
    ));

    expect($device->refresh()->revoked_at)->toBeNull();
});

it('revoca invece di cancellare quando il pacchetto chiama delete', function (): void {
    /*
     * Il gestore dei referti del pacchetto cancella l'iscrizione scaduta. Qui
     * la riga è un dispositivo di §15.8, e cancellarla farebbe ricomparire lo
     * stesso browser alla prima iscrizione automatica.
     */
    $device = Device::factory()->create();

    $iscrizione = WebPushSubscription::query()->findOrFail($device->getKey());
    $iscrizione->delete();

    expect(Device::query()->whereKey($device->getKey())->exists())->toBeTrue()
        ->and($device->refresh()->revoked_at)->not->toBeNull()
        // E lo scope globale non la restituisce più: è ciò che fa ripiegare
        // sull'email al giro successivo.
        ->and(WebPushSubscription::query()->whereKey($device->getKey())->exists())->toBeFalse();
});

function refertoScaduto(string $endpoint): MessageSentReport
{
    return refertoPush($endpoint, 410);
}

function refertoNonScaduto(string $endpoint): MessageSentReport
{
    return refertoPush($endpoint, 503);
}

/**
 * Un referto del servizio push. Richiesta e risposta sono oggetti veri e non
 * finti: `MessageSentReport::getEndpoint()` legge l'indirizzo dalla richiesta
 * e `isSubscriptionExpired()` guarda lo stato della risposta — due letture che
 * un doppione dovrebbe imitare per intero, con il rischio di imitarle male.
 */
function refertoPush(string $endpoint, int $status): MessageSentReport
{
    return new MessageSentReport(
        new Request('POST', $endpoint),
        new Response($status),
        false,
        'errore di prova',
    );
}
