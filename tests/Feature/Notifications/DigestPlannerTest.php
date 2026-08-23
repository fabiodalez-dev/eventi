<?php

declare(strict_types=1);

use App\Enums\FollowableType;
use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Scheduled\ScheduledMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * I riepiloghi di §15.4 e la loro pianificazione anticipata.
 *
 * Le righe nascono **prima** dell'ora di invio proprio perché §15.5 vuole che
 * ogni invio previsto sia ispezionabile in anticipo dal pannello. Ripetere la
 * pianificazione non aggiunge nulla: la chiave di deduplica porta la settimana
 * o il giorno.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('programma il riepilogo giornaliero all orario scelto, nel fuso di chi lo riceve', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00');

    $user = User::factory()->create([
        'timezone' => 'Europe/Rome',
        'daily_digest_time' => '19:30:00',
        'notification_preferences' => ['daily_digest' => true],
    ]);

    $this->artisan('notifications:plan')->assertSuccessful();

    $row = ScheduledNotification::query()->ofType(NotificationType::DailyDigest->value)->firstOrFail();

    expect($row->user_id)->toBe($user->getKey())
        ->and($row->send_at?->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-09-07 19:30')
        ->and($row->dedupe_key)->toBe(sprintf('daily_digest:user_%d:2026-09-07', $user->getKey()));
});

it('non aggiunge nulla se la pianificazione viene ripetuta', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00');

    User::factory()->create(['notification_preferences' => ['daily_digest' => true]]);

    $this->artisan('notifications:plan');
    $this->artisan('notifications:plan');

    expect(ScheduledNotification::query()->ofType(NotificationType::DailyDigest->value)->count())->toBe(1);
});

it('non programma il riepilogo giornaliero a chi non lo ha acceso', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00');

    User::factory()->create();

    $this->artisan('notifications:plan');

    expect(ScheduledNotification::query()->ofType(NotificationType::DailyDigest->value)->count())->toBe(0);
});

it('manda la newsletter del weekend solo a chi ha dato il consenso, e di giovedì', function (): void {
    // §15.9: il marketing è giuridicamente distinto dalle notifiche
    // transazionali. Non basta una preferenza accesa: serve la data del
    // consenso in `marketing_opt_in_at`.
    freezeLocal($this->city, '2026-09-09 12:00');

    $consenting = User::factory()->marketingOptedIn()->create();
    User::factory()->create();

    $this->artisan('notifications:plan')->assertSuccessful();

    $rows = ScheduledNotification::query()->ofType(NotificationType::WeekendNewsletter->value)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->user_id)->toBe($consenting->getKey())
        ->and($rows->first()?->send_at?->setTimezone($this->city->timezone)->format('Y-m-d H:i'))
        ->toBe('2026-09-10 16:00');
});

it('salta il riepilogo che non avrebbe niente da dire', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00');

    Notification::fake();

    $user = User::factory()->create(['notification_preferences' => ['daily_digest' => true]]);

    $this->artisan('notifications:plan');

    $row = ScheduledNotification::query()->ofType(NotificationType::DailyDigest->value)->firstOrFail();

    Carbon::setTestNow($row->send_at);
    $this->artisan('notifications:send');

    Notification::assertNothingSent();

    expect($row->fresh()?->last_error)->toBe('nothing_to_send')
        ->and($user->notifications()->count())->toBe(0);
});

it('avvisa il referente del locale che non pubblica da tre settimane, una volta al mese', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00');

    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
    $owner = User::factory()->owning($venue)->create();

    $this->artisan('notifications:plan');
    $this->artisan('notifications:plan');

    $rows = ScheduledNotification::query()->ofType(NotificationType::VenueInactive->value)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->user_id)->toBe($owner->getKey())
        ->and($rows->first()?->dedupe_key)->toBe(
            sprintf('venue_inactive:user_%d:venue_%d:2026-09', $owner->getKey(), $venue->getKey()),
        );
});

it('non avvisa il locale che ha pubblicato di recente', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00');

    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
    User::factory()->owning($venue)->create();

    occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00', venue: $venue);

    $this->artisan('notifications:plan');

    expect(ScheduledNotification::query()->ofType(NotificationType::VenueInactive->value)->count())->toBe(0);
});

it('purga l archivio degli invii oltre i dodici mesi', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00');

    $user = User::factory()->create();

    NotificationLog::query()->create([
        'user_id' => $user->getKey(),
        'type' => NotificationType::EventReminder->value,
        'channel' => 'mail',
        'sent_at' => now()->subMonths(13),
    ]);

    NotificationLog::query()->create([
        'user_id' => $user->getKey(),
        'type' => NotificationType::EventReminder->value,
        'channel' => 'mail',
        'sent_at' => now()->subMonths(2),
    ]);

    $this->artisan('notifications:plan')->assertSuccessful();

    expect(NotificationLog::query()->count())->toBe(1);
});

it('scrive nel riepilogo settimanale solo le novità della settimana', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00');

    Notification::fake();

    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
    $user = User::factory()->create();

    $user->follows()->create([
        'followable_type' => FollowableType::Venue->value,
        'followable_id' => $venue->getKey(),
        'notify' => true,
    ]);

    // Una data pubblicata due settimane fa non è una novità: la finestra del
    // riepilogo è di sette giorni.
    $old = occurrenceAtLocal($this->city, $this->category, '2026-09-25 21:00', venue: $venue);
    $old->event->forceFill(['updated_at' => now()->subDays(20)])->saveQuietly();
    $old->forceFill(['updated_at' => now()->subDays(20)])->saveQuietly();

    occurrenceAtLocal($this->city, $this->category, '2026-09-26 21:00', venue: $venue);

    $this->artisan('notifications:plan');

    $row = ScheduledNotification::query()->ofType(NotificationType::VenueDigest->value)->firstOrFail();

    Carbon::setTestNow($row->send_at);
    $this->artisan('notifications:send');

    Notification::assertSentTo($user, ScheduledMessage::class, function (ScheduledMessage $notification): bool {
        return count($notification->message->items) === 1;
    });
});
