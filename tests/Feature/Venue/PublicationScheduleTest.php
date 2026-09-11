<?php

declare(strict_types=1);

use App\Actions\PublishEventAction;
use App\Actions\ScheduleEventPublication;
use App\Enums\EventStatus;
use App\Enums\VerificationStatus;
use App\Filament\Venue\Support\EventPublication;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\VenueIsolationScenario;

it('publishes a due authorized schedule once and waits for its deadline', function (): void {
    $s = VenueIsolationScenario::make();
    $s->venueA->update(['auto_publish' => true]);
    $event = $s->publishedEventA;
    $event->update(['status' => EventStatus::Draft]);
    app(ScheduleEventPublication::class)->schedule($event, $s->ownerA, CarbonImmutable::now()->addHour());
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($event->fresh()->status)->toBe(EventStatus::Draft);
    $this->travel(61)->minutes();
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($event->fresh()->status)->toBe(EventStatus::Published)
        ->and($event->fresh()->scheduled_publish_at)->toBeNull();
    $this->artisan('events:publish-due')->assertSuccessful();
});

it('cannot use scheduling to bypass admin approval or a revoked permission', function (): void {
    $s = VenueIsolationScenario::make();
    $s->venueA->update(['auto_publish' => false]);
    $event = $s->publishedEventA;
    $event->update(['status' => EventStatus::Draft]);
    app(ScheduleEventPublication::class)->schedule($event, $s->ownerA, CarbonImmutable::now()->addHour());
    $this->travel(61)->minutes();
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($event->fresh()->status)->toBe(EventStatus::Pending);
    $event->update(['status' => EventStatus::Rejected]);
    expect($event->fresh()->scheduled_publish_at)->toBeNull();
});

it('derives venue confirmation from the venue and rejects editorial field changes by its owner', function (): void {
    $s = VenueIsolationScenario::make();
    $s->venueA->update(['is_verified' => true]);
    expect($s->publishedEventA->fresh()->verification_status)->toBe(VerificationStatus::VenueConfirmed);
    $s->venueA->update(['is_verified' => false]);
    expect($s->publishedEventA->fresh()->verification_status)->toBe(VerificationStatus::Unverified);
    $this->actingAs($s->ownerA);
    expect(fn () => $s->publishedEventA->update(['verification_status' => VerificationStatus::EditorialChecked]))
        ->toThrow(AuthorizationException::class);
});

it('clears stale amounts when an event becomes free', function (): void {
    $s = VenueIsolationScenario::make();
    $s->publishedEventA->update(['price_type' => 'free', 'price_min' => 10, 'price_max' => 20]);
    expect($s->publishedEventA->fresh()->price_min)->toBeNull()
        ->and($s->publishedEventA->fresh()->price_max)->toBeNull();
});

it('lets staff approve a future request without publishing it early', function (): void {
    $s = VenueIsolationScenario::make();
    $s->venueA->update(['auto_publish' => false]);
    $event = $s->publishedEventA;
    $event->update(['status' => EventStatus::Draft]);
    app(ScheduleEventPublication::class)->schedule($event, $s->ownerA, CarbonImmutable::now()->addHour());
    $this->actingAs($s->admin);
    app(PublishEventAction::class)->publish($event);
    expect($event->fresh()->status)->toBe(EventStatus::Draft)
        ->and($event->fresh()->publication_scheduled_by)->toBe($s->admin->id);
    $this->travel(61)->minutes();
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($event->fresh()->status)->toBe(EventStatus::Published);
});

it('rechecks autonomous publication permission at execution', function (): void {
    $s = VenueIsolationScenario::make();
    $s->venueA->update(['auto_publish' => true]);
    $event = $s->publishedEventA;
    $event->update(['status' => EventStatus::Draft]);
    app(ScheduleEventPublication::class)->schedule($event, $s->ownerA, CarbonImmutable::now()->addHour());
    $s->venueA->update(['auto_publish' => false]);
    $this->travel(61)->minutes();
    $this->artisan('events:publish-due')->assertSuccessful();
    expect($event->fresh()->status)->not->toBe(EventStatus::Published);
});

it('does not turn a rejected event into an autonomous publication by resubmitting it twice', function (): void {
    $s = VenueIsolationScenario::make();
    $s->venueA->update(['auto_publish' => true]);
    $event = $s->publishedEventA;
    $event->update(['status' => EventStatus::Rejected, 'rejection_reason' => 'Da verificare']);
    $this->actingAs($s->ownerA);
    EventPublication::submit($event);
    EventPublication::submit($event);
    expect($event->fresh()->status)->toBe(EventStatus::Pending);
});

it('requires a new approval after a venue changes an approved scheduled event', function (): void {
    $s = VenueIsolationScenario::make();
    $s->venueA->update(['auto_publish' => false]);
    $event = $s->publishedEventA;
    $event->update(['status' => EventStatus::Draft]);
    app(ScheduleEventPublication::class)->schedule($event, $s->admin, CarbonImmutable::now()->addHour());
    $this->actingAs($s->ownerA);
    $event->update(['description' => 'Contenuto cambiato dopo l’approvazione']);
    expect($event->fresh()->status)->toBe(EventStatus::Pending)
        ->and($event->fresh()->publication_scheduled_by)->toBe($s->ownerA->id);
});
