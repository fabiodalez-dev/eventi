<?php

declare(strict_types=1);

use App\Actions\Account\FollowSubject;
use App\Actions\PublishEventAction;
use App\DTOs\NotificationMessage;
use App\Enums\EventStatus;
use App\Enums\FollowableType;
use App\Enums\NotificationType;
use App\Enums\VerificationStatus;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Services\Notifications\MessageFactory;
use Carbon\Carbon;

/**
 * Gli eventi dimostrativi (`is_demo`) stanno sul sito con il loro avviso,
 * ma non partono mai da soli verso qualcuno: né l'annuncio a chi segue il
 * locale, né la notizia allo staff, né i riepiloghi. Sono le regole che
 * permettono a `events:investor-demo` e `demo:showcase` di girare sul sito
 * pubblico senza scrivere a persone vere di appuntamenti inventati.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    // Giovedì: stasera è oggi, il weekend è fra due giorni.
    freezeLocal($this->city, '2026-09-10 12:00');
    $this->venue = Venue::factory()->approved()->verified()->create(['city_id' => $this->city->id]);
    $this->follower = User::factory()->marketingOptedIn()->create(['notification_preferences' => ['venue_digest' => true, 'daily_digest' => true]]);
    app(FollowSubject::class)($this->follower, FollowableType::Venue, $this->venue->id);
});

afterEach(fn () => Carbon::setTestNow());

it('non annuncia un evento dimostrativo né a chi segue il locale né allo staff', function (): void {
    $owner = User::factory()->owning($this->venue)->create();

    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: ['is_demo' => true], venue: $this->venue);
    expect(ScheduledNotification::query()->ofType(NotificationType::VenueNewEvent->value)->count())->toBe(0);

    $draft = occurrenceAtLocal($this->city, $this->category, '2026-09-13 21:00', event: ['is_demo' => true, 'status' => EventStatus::Draft, 'published_at' => null], venue: $this->venue);
    app(PublishEventAction::class)->publish($draft->event);
    $draft->event->update(['status' => EventStatus::Rejected]);
    expect(ScheduledNotification::query()->count())->toBe(0);

    // Controprova: lo stesso percorso con un evento vero raggiunge entrambi.
    $real = occurrenceAtLocal($this->city, $this->category, '2026-09-14 21:00', event: ['status' => EventStatus::Draft, 'published_at' => null], venue: $this->venue);
    app(PublishEventAction::class)->publish($real->event);
    expect(ScheduledNotification::query()->ofType(NotificationType::VenueNewEvent->value)->where('user_id', $this->follower->id)->count())->toBe(1)
        ->and(ScheduledNotification::query()->ofType(NotificationType::EventPublished->value)->where('user_id', $owner->id)->count())->toBe(1);
});

it('resta «non verificato» anche in un locale verificato, prima e dopo la verifica', function (): void {
    $demo = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: ['is_demo' => true], venue: $this->venue)->event;
    $real = occurrenceAtLocal($this->city, $this->category, '2026-09-12 22:00', venue: $this->venue)->event;
    expect($demo->verification_status)->toBe(VerificationStatus::Unverified)
        ->and($real->verification_status)->toBe(VerificationStatus::VenueConfirmed);

    $this->venue->update(['is_verified' => false]);
    $this->venue->update(['is_verified' => true]);
    expect($demo->fresh()->verification_status)->toBe(VerificationStatus::Unverified)
        ->and($real->fresh()->verification_status)->toBe(VerificationStatus::VenueConfirmed);
});

it('tiene gli eventi dimostrativi fuori dai riepiloghi e dalla newsletter del weekend', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:00', event: ['is_demo' => true, 'title' => 'Stasera finta'], venue: $this->venue);
    occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:30', event: ['title' => 'Stasera vera'], venue: $this->venue);
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: ['is_demo' => true, 'title' => 'Sabato finto'], venue: $this->venue);
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30', event: ['title' => 'Sabato vero'], venue: $this->venue);

    $titles = function (NotificationType $type): array {
        $message = app(MessageFactory::class)->build(new ScheduledNotification(['type' => $type->value]), $this->follower);
        expect($message)->toBeInstanceOf(NotificationMessage::class);

        return collect($message->items)->pluck('title')->sort()->values()->all();
    };

    expect($titles(NotificationType::DailyDigest))->toBe(['Stasera vera'])
        ->and($titles(NotificationType::VenueDigest))->toBe(['Sabato vero', 'Stasera vera'])
        ->and($titles(NotificationType::WeekendNewsletter))->toBe(['Sabato vero']);
});
