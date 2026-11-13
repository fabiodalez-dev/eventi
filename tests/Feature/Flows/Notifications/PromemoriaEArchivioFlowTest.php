<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\SavedSearch;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * Flussi del motore delle notifiche visti da chi le riceve (§15.4–§15.6):
 * si salva una data dall'app, il worker vero (`notifications:send`) decide se
 * e quando consegnare, e il messaggio finisce nell'archivio in-app che l'app
 * rilegge, segna come letto e svuota.
 *
 * Qui il dispatcher gira davvero, con i canali veri (posta `array` e
 * archivio `database`), tranne dove serve solo leggere la decisione presa.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Le email effettivamente consegnate al trasporto `array` della suite.
 *
 * @return list<Email>
 */
function flussoNotificheEmailConsegnate(): array
{
    /** @var TransportInterface $transport */
    $transport = Mail::mailer()->getSymfonyTransport();

    $email = [];

    foreach ($transport->messages() as $sent) {
        $original = $sent->getOriginalMessage();

        if ($original instanceof Email) {
            $email[] = $original;
        }
    }

    return $email;
}

it('salva una data dall app, riceve il promemoria il giorno prima per email e lo ritrova letto nell archivio', function (): void {
    $giulia = User::factory()->create(['email' => 'giulia@example.test']);
    $token = $giulia->createToken('Telefono')->plainTextToken;

    $data = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00', event: ['title' => 'Jazz al Pedrocchi']);

    // 1. Salva la data dall'app.
    $this->withToken($token)->postJson('/api/v1/me/saved', ['occurrence_id' => $data->getKey()])
        ->assertCreated()
        ->assertJsonPath('data.occurrence_id', (int) $data->getKey());

    // 2. Il salvataggio programma i due promemoria predefiniti (24 e 3 ore prima).
    $righe = ScheduledNotification::query()
        ->ofType(NotificationType::EventReminder->value)
        ->where('user_id', $giulia->getKey())
        ->orderBy('send_at')
        ->get();

    expect($righe)->toHaveCount(2)
        ->and($righe->map(fn (ScheduledNotification $riga): mixed => $riga->context('hours'))->all())->toBe([24, 3])
        ->and($righe[0]->send_at?->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-09-04 21:00')
        ->and($righe[1]->send_at?->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-09-05 18:00');

    // 3. Cinque minuti prima dell'ora il worker non prende in mano nulla.
    Carbon::setTestNow(localInstant($this->city, '2026-09-04 20:55'));
    $this->artisan('notifications:send')->assertSuccessful();

    expect($giulia->notifications()->count())->toBe(0)
        ->and(flussoNotificheEmailConsegnate())->toBe([]);

    // 4. All'ora giusta parte: email vera e riga nell'archivio.
    Carbon::setTestNow(localInstant($this->city, '2026-09-04 21:00'));
    $this->artisan('notifications:send')->assertSuccessful();

    $email = flussoNotificheEmailConsegnate();

    expect($email)->toHaveCount(1)
        ->and($email[0]->getTo()[0]->getAddress())->toBe('giulia@example.test')
        ->and((string) $email[0]->getHtmlBody())->toContain('Jazz al Pedrocchi');

    $inviata = $righe[0]->fresh();

    expect($inviata?->status)->toBe(NotificationStatus::Sent)
        ->and($inviata?->channel)->toBe(NotificationChannel::Mail)
        ->and($righe[1]->fresh()?->status)->toBe(NotificationStatus::Pending)
        ->and(NotificationLog::query()->where('user_id', $giulia->getKey())->value('type'))->toBe('event_reminder');

    // 5. L'app rilegge l'archivio: una notifica non letta, col collegamento alla scheda.
    $archivio = $this->withToken($token)->getJson('/api/v1/me/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'event_reminder')
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('data.0.data.occurrence_id', (int) $data->getKey())
        ->assertJsonPath('meta.unread_count', 1);

    expect((string) $archivio->json('data.0.data.url'))->toContain('/eventi/');

    // 6. La apre: diventa letta e il contatore torna a zero.
    $id = (string) $archivio->json('data.0.id');

    $this->withToken($token)->patchJson('/api/v1/me/notifications/'.$id.'/read')
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.read', true);

    expect($giulia->unreadNotifications()->count())->toBe(0);

    $this->withToken($token)->getJson('/api/v1/me/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.read', true)
        ->assertJsonPath('meta.unread_count', 0);
});

it('rimanda il promemoria che cade nelle ore di silenzio all uscita dal silenzio, senza perderlo', function (): void {
    Notification::fake();

    // Silenzio dalle 20 alle 23: il promemoria delle 21 del giorno prima ci cade dentro.
    $marco = User::factory()->create(['quiet_hours' => ['from' => '20:00', 'to' => '23:00']]);
    $token = $marco->createToken('Telefono')->plainTextToken;

    $data = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');

    $this->withToken($token)->postJson('/api/v1/me/saved', ['occurrence_id' => $data->getKey()])->assertCreated();

    // L'app mostra le ore di silenzio che il motore applicherà davvero.
    $this->withToken($token)->getJson('/api/v1/me/notification-preferences')
        ->assertOk()
        ->assertJsonPath('data.quiet_hours_effective', ['from' => '20:00', 'to' => '23:00']);

    $ventiquattro = ScheduledNotification::query()->ofType('event_reminder')->orderBy('send_at')->firstOrFail();

    // Alle 21 del giorno prima: rimandato, non inviato né saltato.
    Carbon::setTestNow(localInstant($this->city, '2026-09-04 21:00'));
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertNothingSent();

    $rimandata = $ventiquattro->fresh();

    expect($rimandata?->status)->toBe(NotificationStatus::Pending)
        ->and($rimandata?->send_at?->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-09-04 23:00')
        ->and($rimandata?->attempts)->toBe(0);

    // Alle 22:59 ancora niente: il silenzio non è finito.
    Carbon::setTestNow(localInstant($this->city, '2026-09-04 22:59'));
    $this->artisan('notifications:send')->assertSuccessful();
    Notification::assertNothingSent();

    // Alle 23 esce, una volta sola.
    Carbon::setTestNow(localInstant($this->city, '2026-09-04 23:00'));
    $this->artisan('notifications:send')->assertSuccessful();
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentToTimes($marco, ScheduledMessage::class, 1);
    Notification::assertSentTo($marco, ScheduledMessage::class, fn (ScheduledMessage $n): bool => $n->message->type === NotificationType::EventReminder
        && $n->message->occurrenceId === (int) $data->getKey());

    expect($ventiquattro->fresh()?->status)->toBe(NotificationStatus::Sent);

    // Il promemoria delle 18 del giorno stesso è fuori dal silenzio e parte puntuale.
    Carbon::setTestNow(localInstant($this->city, '2026-09-05 18:00'));
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentToTimes($marco, ScheduledMessage::class, 2);
});

it('rispetta le preferenze nel momento dell invio: spento salta, riacceso riparte', function (): void {
    Notification::fake();

    $anna = User::factory()->create();
    $token = $anna->createToken('Telefono')->plainTextToken;

    $data = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $this->withToken($token)->postJson('/api/v1/me/saved', ['occurrence_id' => $data->getKey()])->assertCreated();

    // Dopo aver salvato spegne i promemoria dall'app.
    $this->withToken($token)->patchJson('/api/v1/me/notification-preferences', ['reminders' => false])
        ->assertOk()
        ->assertJsonPath('data.reminders', false)
        ->assertJsonPath('data.cancellations', true);

    // Le righe restano programmate: la decisione si prende all'invio.
    expect(ScheduledNotification::query()->pending()->ofType('event_reminder')->count())->toBe(2);

    Carbon::setTestNow(localInstant($this->city, '2026-09-04 21:00'));
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertNothingSent();

    $saltata = ScheduledNotification::query()->ofType('event_reminder')->orderBy('send_at')->firstOrFail();

    expect($saltata->status)->toBe(NotificationStatus::Skipped)
        ->and($saltata->last_error)->toBe(NotificationSkipReason::PreferenceOff->value)
        ->and(NotificationLog::query()->count())->toBe(0);

    // Ci ripensa e li riaccende: il promemoria di tre ore prima arriva.
    $this->withToken($token)->patchJson('/api/v1/me/notification-preferences', ['reminders' => true])
        ->assertOk()
        ->assertJsonPath('data.reminders', true);

    Carbon::setTestNow(localInstant($this->city, '2026-09-05 18:00'));
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentToTimes($anna, ScheduledMessage::class, 1);

    expect(ScheduledNotification::query()->ofStatus(NotificationStatus::Sent)->count())->toBe(1)
        ->and(ScheduledNotification::query()->ofStatus(NotificationStatus::Skipped)->count())->toBe(1);
});

it('segna come lette tutte le notifiche in un colpo e non lascia leggere quelle di un altro', function (): void {
    $luca = User::factory()->create();
    $tokenLuca = $luca->createToken('Telefono')->plainTextToken;

    $intruso = User::factory()->create();
    $tokenIntruso = $intruso->createToken('Telefono')->plainTextToken;

    // Ogni richiesta dell app porta solo il proprio token: il guard di prova non deve ricordare chi ha chiamato prima.
    $come = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $data = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $come($tokenLuca)->postJson('/api/v1/me/saved', ['occurrence_id' => $data->getKey()])->assertCreated();

    // Arrivano entrambi i promemoria.
    Carbon::setTestNow(localInstant($this->city, '2026-09-04 21:00'));
    $this->artisan('notifications:send')->assertSuccessful();
    Carbon::setTestNow(localInstant($this->city, '2026-09-05 18:00'));
    $this->artisan('notifications:send')->assertSuccessful();

    $archivio = $come($tokenLuca)->getJson('/api/v1/me/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.unread_count', 2);

    $primo = (string) $archivio->json('data.0.id');

    // Un altro account prova a segnare come letta una notifica di Luca: non esiste, per lui.
    $come($tokenIntruso)->patchJson('/api/v1/me/notifications/'.$primo.'/read')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect($luca->unreadNotifications()->count())->toBe(2);

    // E il suo «segna tutto come letto» non tocca l'archivio di Luca.
    $come($tokenIntruso)->postJson('/api/v1/me/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.updated', 0);

    expect($luca->unreadNotifications()->count())->toBe(2);

    // Luca ne legge una, poi svuota tutto.
    $come($tokenLuca)->patchJson('/api/v1/me/notifications/'.$primo.'/read')->assertOk();

    $come($tokenLuca)->postJson('/api/v1/me/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.updated', 1)
        ->assertJsonPath('data.unread_count', 0);

    $come($tokenLuca)->getJson('/api/v1/me/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.read', true)
        ->assertJsonPath('data.1.read', true)
        ->assertJsonPath('meta.unread_count', 0);
});

it('avvisa chi ha salvato una ricerca quando esce una data nuova che le corrisponde', function (): void {
    $sara = User::factory()->create();
    $token = $sara->createToken('Telefono')->plainTextToken;

    $musica = testCategory(['slug' => 'musica-live', 'name' => 'Musica live']);
    $teatro = testCategory(['slug' => 'teatro', 'name' => 'Teatro']);

    freezeLocal($this->city, '2026-10-23 09:00');

    // Ciò che è già in catalogo non è una novità.
    occurrenceAtLocal($this->city, $musica, '2026-10-30 21:00', event: ['title' => 'Già in catalogo']);

    $this->withToken($token)->postJson('/api/v1/me/searches', ['filters' => ['category' => 'musica-live']])
        ->assertCreated();

    $this->withToken($token)->getJson('/api/v1/me/searches')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.filters.category', 'musica-live');

    // Il controllo orario di subito non trova nulla di nuovo.
    $this->artisan('searches:notify')->expectsOutput('0')->assertSuccessful();

    // Due ore dopo la redazione pubblica una data che corrisponde e una che no.
    freezeLocal($this->city, '2026-10-23 11:00');
    $nuova = occurrenceAtLocal($this->city, $musica, '2026-10-31 21:00', event: ['title' => 'Appena pubblicato']);
    occurrenceAtLocal($this->city, $teatro, '2026-10-31 21:00', event: ['title' => 'Teatro nuovo']);

    $this->artisan('searches:notify')->expectsOutput('1')->assertSuccessful();

    $riga = ScheduledNotification::query()->ofType(NotificationType::SavedSearchMatch->value)->sole();

    expect($riga->user_id)->toBe($sara->getKey())
        ->and($riga->context('occurrence_ids'))->toBe([(int) $nuova->getKey()]);

    // Il worker la consegna, e finisce nell'archivio che l'app legge.
    $this->artisan('notifications:send')->assertSuccessful();

    expect($riga->fresh()?->status)->toBe(NotificationStatus::Sent);

    $this->withToken($token)->getJson('/api/v1/me/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'saved_search_match')
        ->assertJsonPath('data.0.data.items.0.title', 'Appena pubblicato');

    // Nessuna novità dopo: nessuna seconda notifica.
    freezeLocal($this->city, '2026-10-24 11:00');
    $this->artisan('searches:notify')->expectsOutput('0')->assertSuccessful();

    expect(ScheduledNotification::query()->ofType(NotificationType::SavedSearchMatch->value)->count())->toBe(1)
        ->and(SavedSearch::query()->sole()->last_checked_at)->not->toBeNull();
});
