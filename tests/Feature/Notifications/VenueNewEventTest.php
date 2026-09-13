<?php

declare(strict_types=1);

use App\Actions\Account\FollowSubject;
use App\Actions\PublishEventAction;
use App\Enums\EventStatus;
use App\Enums\FollowableType;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationType;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Services\Notifications\MessageFactory;
use App\Support\EventUrl;
use Illuminate\Support\Facades\Notification;

afterEach(fn () => Carbon\Carbon::setTestNow());

it('announces the first publication to notifying followers only and links to the date', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-13 12:00');
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $follower = User::factory()->create();
    $muted = User::factory()->create();
    app(FollowSubject::class)($follower, FollowableType::Venue, $venue->id);
    app(FollowSubject::class)($muted, FollowableType::Venue, $venue->id, false);
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-20 21:00', event: ['status' => EventStatus::Draft, 'published_at' => null], venue: $venue);
    expect(ScheduledNotification::query()->ofType('venue_new_event')->count())->toBe(0);
    app(PublishEventAction::class)->publish($date->event);
    $row = ScheduledNotification::query()->ofType('venue_new_event')->sole();
    expect($row->user_id)->toBe($follower->id);
    $message = app(MessageFactory::class)->build($row, $follower);
    expect($message->occurrenceId)->toBe($date->id)
        ->and($message->url)->toBe(EventUrl::occurrence($date));
    Notification::fake();
    $this->artisan('notifications:send')->assertSuccessful();
    Notification::assertSentTo($follower, ScheduledMessage::class, fn ($notification) => $notification->message->type === NotificationType::VenueNewEvent);
    Notification::assertNotSentTo($muted, ScheduledMessage::class);
    $date->event->update(['status' => EventStatus::Draft]);
    $lateFollower = User::factory()->create();
    app(FollowSubject::class)($lateFollower, FollowableType::Venue, $venue->id);
    app(PublishEventAction::class)->publish($date->event);
    expect(ScheduledNotification::query()->ofType('venue_new_event')->count())->toBe(1);
});

it('handles events created published and rechecks follows and visibility at delivery', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-13 12:00');
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $user = User::factory()->create();
    $follow = app(FollowSubject::class)($user, FollowableType::Venue, $venue->id);
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-20 21:00', venue: $venue);
    $row = ScheduledNotification::query()->ofType('venue_new_event')->sole();
    $follow->delete();
    expect(app(MessageFactory::class)->build($row, $user))->toBe(NotificationSkipReason::PreferenceOff);
    app(FollowSubject::class)($user, FollowableType::Venue, $venue->id);
    $date->event->update(['status' => EventStatus::Draft]);
    expect(app(MessageFactory::class)->build($row->fresh(), $user))->toBe(NotificationSkipReason::MissingSubject);
});
