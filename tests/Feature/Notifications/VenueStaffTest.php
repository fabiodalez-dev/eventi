<?php

declare(strict_types=1);

use App\Actions\ModerateEventAction;
use App\Actions\PublishEventAction;
use App\Enums\EventStatus;
use App\Enums\NotificationType;
use App\Models\Event;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Scheduled\ScheduledMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * L'ultima riga della tabella di §15.4: «ai gestori — evento pubblicato /
 * rifiutato / non pubblichi da 21 giorni».
 *
 * L'esito di una proposta è la sola notizia che chi l'ha scritta sta davvero
 * aspettando: parte dal cambio di stato dell'evento e non da una data.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
    $this->owner = User::factory()->owning($this->venue)->create();

    $this->occurrence = occurrenceAtLocal(
        $this->city,
        $this->category,
        '2026-09-20 21:00',
        event: ['status' => EventStatus::Draft, 'published_at' => null],
        venue: $this->venue,
    );

    /** @var Event $event */
    $event = $this->occurrence->event;
    $this->event = $event;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('avvisa chi gestisce il locale che l evento è online', function (): void {
    Notification::fake();

    app(PublishEventAction::class)->publish($this->event);

    $row = ScheduledNotification::query()->ofType(NotificationType::EventPublished->value)->firstOrFail();

    expect($row->user_id)->toBe($this->owner->getKey())
        ->and($row->dedupe_key)->toBe(
            sprintf('event_published:user_%d:event_%d', $this->owner->getKey(), $this->event->getKey()),
        );

    $this->artisan('notifications:send')->assertSuccessful();

    Notification::assertSentTo($this->owner, ScheduledMessage::class, function (ScheduledMessage $notification): bool {
        return $notification->message->type === NotificationType::EventPublished
            && str_contains($notification->message->url, '/eventi/');
    });
});

it('avvisa chi gestisce il locale del rifiuto, con il motivo', function (): void {
    Notification::fake();

    app(ModerateEventAction::class)->reject($this->event, 'Manca l\'indirizzo del luogo');

    $this->artisan('notifications:send');

    Notification::assertSentTo($this->owner, ScheduledMessage::class, function (ScheduledMessage $notification): bool {
        return $notification->message->type === NotificationType::EventRejected
            && collect($notification->message->lines)->contains(fn (string $line): bool => str_contains($line, 'indirizzo del luogo'));
    });
});

it('non ripete l avviso di pubblicazione a ogni salvataggio', function (): void {
    app(PublishEventAction::class)->publish($this->event);

    $this->event->title = 'Titolo corretto';
    $this->event->save();

    expect(ScheduledNotification::query()->ofType(NotificationType::EventPublished->value)->count())->toBe(1);
});
