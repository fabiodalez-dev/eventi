<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use Carbon\Carbon;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Notification;

/**
 * Il worker di §15.5: cosa prende in mano, cosa scrive, e cosa fa quando
 * qualcosa va storto.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('non manda nulla a chi non ha verificato l indirizzo', function (): void {
    // §15.2: «un account non verificato può salvare, non può ricevere».
    Notification::fake();

    $user = User::factory()->unverified()->create();
    app(SaveOccurrences::class)->one($user, $this->occurrence);

    Carbon::setTestNow(localInstant($this->city, '2026-09-05 18:30'));
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertNothingSent();

    expect(ScheduledNotification::query()->ofStatus(NotificationStatus::Skipped)->pluck('last_error')->unique()->all())
        ->toBe(['unverified'])
        ->and(NotificationLog::query()->count())->toBe(0);
});

it('non tocca le righe la cui ora non è ancora arrivata', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    app(SaveOccurrences::class)->one($user, $this->occurrence);

    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertNothingSent();

    expect(ScheduledNotification::query()->pending()->count())->toBe(2);
});

it('invia il promemoria, scrive l archivio e segna la riga', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    app(SaveOccurrences::class)->one($user, $this->occurrence);

    // Ventiquattro ore prima: la prima delle due soglie di §15.4. Alle 18:00
    // del giorno stesso sarebbero scadute entrambe.
    Carbon::setTestNow(localInstant($this->city, '2026-09-04 21:00'));

    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentTimes(ScheduledMessage::class, 1);

    Notification::assertSentTo($user, ScheduledMessage::class, function (ScheduledMessage $notification) use ($user): bool {
        return $notification->message->type === NotificationType::EventReminder
            && $notification->via($user) === ['mail', 'database']
            && $notification->databaseType($user) === 'event_reminder'
            && str_contains($notification->message->url, '/eventi/')
            && $notification->message->occurrenceId === (int) $this->occurrence->getKey();
    });

    $sent = ScheduledNotification::query()->ofStatus(NotificationStatus::Sent)->firstOrFail();

    expect($sent->context('hours'))->toBe(24)
        ->and($sent->sent_at)->not->toBeNull()
        ->and($sent->attempts)->toBe(1)
        ->and(NotificationLog::query()->count())->toBe(1)
        ->and(NotificationLog::query()->first()?->type)->toBe(NotificationType::EventReminder->value);
});

it('non manda due volte la stessa riga', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    app(SaveOccurrences::class)->one($user, $this->occurrence);

    Carbon::setTestNow(localInstant($this->city, '2026-09-04 21:00'));

    $this->artisan('notifications:send');
    $this->artisan('notifications:send');

    Notification::assertSentTimes(ScheduledMessage::class, 1);
});

it('riprova con attese crescenti e si arrende al terzo tentativo', function (): void {
    // §15.5: «failed (con retry esponenziale, max 3)».
    $this->mock(Dispatcher::class, function ($mock): void {
        $mock->shouldReceive('send')->andThrow(new RuntimeException('server di posta irraggiungibile'));
    });

    $user = User::factory()->create();
    app(SaveOccurrences::class)->one($user, $this->occurrence);

    ScheduledNotification::query()->where('id', '!=', ScheduledNotification::query()->min('id'))->delete();

    $row = ScheduledNotification::query()->firstOrFail();
    $row->deferTo(now()->toImmutable());

    $attempts = [];

    foreach ([5, 15, 45] as $backoff) {
        Carbon::setTestNow($row->fresh()?->send_at);
        $this->artisan('notifications:send')->assertSuccessful();

        $row->refresh();
        $attempts[] = $row->attempts;
    }

    expect($attempts)->toBe([1, 2, 3])
        ->and($row->status)->toBe(NotificationStatus::Failed)
        ->and($row->last_error)->toContain('irraggiungibile');
});

it('non prende in mano una riga annullata dalla redazione', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    app(SaveOccurrences::class)->one($user, $this->occurrence);

    ScheduledNotification::query()->firstOrFail()->markCancelled();

    Carbon::setTestNow(localInstant($this->city, '2026-09-05 18:00'));
    $this->artisan('notifications:send');

    expect(ScheduledNotification::query()->ofStatus(NotificationStatus::Cancelled)->count())->toBe(1)
        ->and(ScheduledNotification::query()->ofStatus(NotificationStatus::Sent)->count())->toBe(1);
});
