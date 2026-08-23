<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Enums\FollowableType;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\OccurrenceStatus;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Scheduled\ScheduledMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * §18, **scenario K — volume**.
 *
 * «Un utente che segue 8 locali attivi non riceve più di 2 notifiche in un
 * giorno né alcuna dentro le proprie quiet hours.»
 *
 * Le tre regole di §15.4 che lo rendono vero sono verificate una per una:
 * il riepilogo aggregato (mai una notifica per singolo evento nuovo), il tetto
 * giornaliero, e le ore di silenzio — che **spostano** invece di cancellare,
 * tranne per gli annullamenti, che non aspettano il mattino.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('trasforma otto locali seguiti in un solo riepilogo, non in otto notifiche', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00');

    $user = User::factory()->create();

    for ($i = 0; $i < 8; $i++) {
        $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

        occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00', venue: $venue);

        $user->follows()->create([
            'followable_type' => FollowableType::Venue->value,
            'followable_id' => $venue->getKey(),
            'notify' => true,
        ]);
    }

    $this->artisan('notifications:plan')->assertSuccessful();

    $digests = ScheduledNotification::query()->ofType(NotificationType::VenueDigest->value)->get();

    expect($digests)->toHaveCount(1);

    // Il martedì alle 18:00, nel fuso di chi riceve.
    $sendAt = $digests->first()?->send_at?->setTimezone($this->city->timezone);

    expect($sendAt?->format('Y-m-d H:i'))->toBe('2026-09-08 18:00');

    Notification::fake();
    Carbon::setTestNow($digests->first()?->send_at?->copy()->addMinute());

    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentTimes(ScheduledMessage::class, 1);

    Notification::assertSentTo($user, ScheduledMessage::class, function (ScheduledMessage $notification): bool {
        // Otto date in un messaggio solo, ciascuna con il proprio
        // collegamento profondo: §15.4 vuole la scheda, mai la home.
        return count($notification->message->items) === 8
            && collect($notification->message->items)->every(fn (array $item): bool => str_contains($item['url'], '/eventi/'));
    });
});

it('non manda più di due notifiche intrusive in un giorno', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00');

    Notification::fake();

    $user = User::factory()->create();

    // Tre serate salvate che vanno esaurite nello stesso pomeriggio: il
    // "sold out" è una tipologia che consuma il tetto (§15.4), a differenza
    // del promemoria di una data messa in agenda a mano.
    for ($i = 0; $i < 3; $i++) {
        $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00');
        app(SaveOccurrences::class)->one($user, $occurrence);

        $occurrence->status = OccurrenceStatus::SoldOut;
        $occurrence->save();
    }

    expect(ScheduledNotification::query()->ofType(NotificationType::EventSoldOut->value)->count())->toBe(3);

    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentTimes(ScheduledMessage::class, 2);

    $rows = ScheduledNotification::query()
        ->ofType(NotificationType::EventSoldOut->value)
        ->orderBy('id')
        ->get();

    expect($rows->pluck('status')->all())->toBe([
        NotificationStatus::Sent,
        NotificationStatus::Sent,
        NotificationStatus::Skipped,
    ])->and($rows->last()?->last_error)->toBe('frequency_cap');
});

it('sposta fuori dal silenzio un invio che vi cade dentro, senza cancellarlo', function (): void {
    freezeLocal($this->city, '2026-09-07 12:00');

    Notification::fake();

    $user = User::factory()->create(['quiet_hours' => ['from' => '23:30', 'to' => '08:00']]);

    // Una serata che comincia alle 3:30 di notte: il promemoria a tre ore
    // cadrebbe a mezzanotte e mezza.
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 03:30');
    app(SaveOccurrences::class)->one($user, $occurrence);

    $reminder = ScheduledNotification::query()
        ->ofType(NotificationType::EventReminder->value)
        ->orderByDesc('send_at')
        ->firstOrFail();

    expect($reminder->send_at?->setTimezone($this->city->timezone)->format('H:i'))->toBe('00:30');

    Carbon::setTestNow($reminder->send_at);
    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertNothingSent();

    $reminder->refresh();

    expect($reminder->status)->toBe(NotificationStatus::Pending)
        ->and($reminder->send_at?->setTimezone($this->city->timezone)->format('Y-m-d H:i'))->toBe('2026-09-20 08:00');
});

it('marca come saltato l invio che, uscito dal silenzio, non serve più', function (): void {
    // §15.4: «se nel frattempo è diventato inutile viene marcato skipped».
    freezeLocal($this->city, '2026-09-07 12:00');

    Notification::fake();

    $user = User::factory()->create(['quiet_hours' => ['from' => '23:30', 'to' => '08:00']]);

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 03:30');
    app(SaveOccurrences::class)->one($user, $occurrence);

    $reminder = ScheduledNotification::query()
        ->ofType(NotificationType::EventReminder->value)
        ->orderByDesc('send_at')
        ->firstOrFail();

    Carbon::setTestNow($reminder->send_at);
    $this->artisan('notifications:send');

    // Alle otto del mattino la serata è finita da ore.
    Carbon::setTestNow(localInstant($this->city, '2026-09-20 08:00'));
    $this->artisan('notifications:send');

    Notification::assertNothingSent();

    expect($reminder->fresh()?->status)->toBe(NotificationStatus::Skipped)
        ->and($reminder->fresh()?->last_error)->toBe('occurrence_past');
});

it('non fa aspettare il mattino a un annullamento', function (): void {
    // §15.4: «quiet hours rispettate su tutti i canali **tranne**
    // annullamenti».
    freezeLocal($this->city, '2026-09-07 12:00');

    Notification::fake();

    $user = User::factory()->create(['quiet_hours' => ['from' => '23:30', 'to' => '08:00']]);
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00');

    app(SaveOccurrences::class)->one($user, $occurrence);

    Carbon::setTestNow(localInstant($this->city, '2026-09-08 01:00'));

    $occurrence->status = OccurrenceStatus::Cancelled;
    $occurrence->save();

    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentTimes(ScheduledMessage::class, 1);

    expect(ScheduledNotification::query()
        ->ofType(NotificationType::EventCancelled->value)
        ->first()?->status)->toBe(NotificationStatus::Sent);
});
