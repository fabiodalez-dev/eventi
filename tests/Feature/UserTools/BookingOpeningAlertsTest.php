<?php

declare(strict_types=1);

use App\Models\BookingOpeningAlert;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Services\Notifications\MessageFactory;
use Carbon\CarbonImmutable;

afterEach(fn () => Carbon\Carbon::setTestNow());

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    freezeLocal($this->city, '2026-10-24 12:00');
    $this->date = occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00', null, [
        'booking_enabled' => true, 'booking_opens_at' => localInstant($this->city, '2026-11-01 10:00')->utc(),
    ]);
    $this->date->event->venue->update(['ticketing_enabled' => true]);
});

it('offers the alert only while the opening is ahead, on the site and the API', function (): void {
    $open = occurrenceAtLocal($this->city, $this->category, '2026-11-21 21:00', null, ['booking_enabled' => true], venue: $this->date->event->venue);
    $withoutTickets = occurrenceAtLocal($this->city, $this->category, '2026-11-22 21:00', null, ['booking_opens_at' => localInstant($this->city, '2026-11-01 10:00')->utc()]);

    $this->actingAs($this->user)->get(route('events.show', $this->date->event))->assertOk()->assertSee(__('ticketing.opening.subscribe'));
    $this->getJson('/api/v1/occurrences/'.$this->date->id)->assertOk()->assertJsonPath('data.booking_opens_at', localInstant($this->city, '2026-11-01 10:00')->utc()->toIso8601String());
    $this->actingAs($this->user)->postJson('/api/v1/me/opening-alerts/'.$open->id)->assertUnprocessable();
    $this->actingAs($this->user)->postJson('/api/v1/me/opening-alerts/'.$withoutTickets->id)->assertUnprocessable();
    $this->actingAs($this->user)->postJson('/api/v1/me/opening-alerts/'.$this->date->id)->assertCreated();
    $this->actingAs($this->user)->postJson('/api/v1/me/opening-alerts/'.$this->date->id)->assertCreated();
    expect($this->actingAs($this->user)->getJson('/api/v1/me/opening-alerts')->json('data'))->toBe([$this->date->id]);
    $this->actingAs($this->user)->get(route('events.show', $this->date->event))->assertSee(__('ticketing.opening.active'));
    $this->actingAs($this->user)->deleteJson('/api/v1/me/opening-alerts/'.$this->date->id)->assertNoContent();
    expect(BookingOpeningAlert::count())->toBe(0);
});

it('sends one alert when the bookings open and closes the request', function (): void {
    $this->actingAs($this->user)->post(route('account.opening-alerts.store', $this->date->id))->assertRedirect();

    $this->artisan('ticketing:announce-openings')->assertSuccessful();
    expect(ScheduledNotification::query()->ofType('bookings_open')->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05', 'UTC'));
    $this->artisan('ticketing:announce-openings')->assertSuccessful();
    $row = ScheduledNotification::query()->ofType('bookings_open')->sole();
    expect($row->user_id)->toBe($this->user->id)->and(BookingOpeningAlert::count())->toBe(0);
    $message = app(MessageFactory::class)->build($row, $this->user);
    expect($message->subject)->toContain($this->date->event->title)->and($message->url)->toContain('/eventi/');
});

it('keeps waiting while bookings are switched off and drops the request for a cancelled date', function (): void {
    $this->actingAs($this->user)->postJson('/api/v1/me/opening-alerts/'.$this->date->id)->assertCreated();
    $this->date->update(['booking_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'UTC'));
    $this->artisan('ticketing:announce-openings')->assertSuccessful();
    expect(BookingOpeningAlert::count())->toBe(1);

    $this->date->update(['status' => 'cancelled']);
    $this->artisan('ticketing:announce-openings')->assertSuccessful();
    expect(BookingOpeningAlert::count())->toBe(0)->and(ScheduledNotification::query()->ofType('bookings_open')->count())->toBe(0);
});
