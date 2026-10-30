<?php

use App\Enums\AdmissionStatus;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\User;
use App\Notifications\BookingMessageSent;
use App\Notifications\GuestBookingConfirmation;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
    (new RolesAndPermissionsSeeder)->run();
    $this->date = occurrenceAt(testCity(), testCategory(), '2026-10-23 18:00:00', '2026-10-23 22:00:00', ['booking_enabled' => true, 'booking_capacity' => 4, 'booking_waitlist' => true]);
    $this->date->event->venue->update(['ticketing_enabled' => true]);
    $this->owner = User::factory()->create();
    $this->date->event->venue->members()->attach($this->owner, ['role' => 'owner']);
    $this->user = User::factory()->create();
    $this->service = app(TicketingService::class);
});

it('writes to confirmed bookers, optionally to the queue, and keeps a history with a daily cap', function (): void {
    $this->service->reserve($this->user, $this->date, ['Ada', 'Bea', 'Cat', 'Dan'], (string) Str::uuid(), false);
    $waiting = User::factory()->create();
    $this->service->reserve($waiting, $this->date, ['Eva'], (string) Str::uuid(), true);
    $this->actingAs($this->owner)->post(route('ticketing.manage.message', $this->date), ['subject' => 'Porta la tessera', 'body' => 'Serve la tessera ARCI.'])
        ->assertRedirect(route('ticketing.manage.show', $this->date));
    Notification::assertSentTo($this->user, BookingMessageSent::class);
    Notification::assertNotSentTo($waiting, BookingMessageSent::class);
    expect(BookingMessage::query()->first()->recipients_count)->toBe(1);
    $this->actingAs($this->owner)->postJson('/api/v1/ticketing/'.$this->date->id.'/message', ['subject' => 'Si parte alle 22', 'body' => 'Porte alle 21.', 'include_waitlisted' => true])
        ->assertCreated()->assertJsonPath('data.recipients_count', 2);
    Notification::assertSentTo($waiting, BookingMessageSent::class);
    $this->actingAs($this->owner)->postJson('/api/v1/ticketing/'.$this->date->id.'/message', ['subject' => 'Tre', 'body' => 'Tre'])->assertCreated();
    $this->actingAs($this->owner)->postJson('/api/v1/ticketing/'.$this->date->id.'/message', ['subject' => 'Quattro', 'body' => 'Quattro'])
        ->assertUnprocessable()->assertJsonPath('error.fields.ticketing.0', __('ticketing.errors.message_limit'));
    $this->actingAs($this->user)->postJson('/api/v1/ticketing/'.$this->date->id.'/message', ['subject' => 'x', 'body' => 'y'])->assertForbidden();
    $this->actingAs($this->owner)->get(route('ticketing.manage.show', $this->date))->assertOk()->assertSee('Porta la tessera');
});

it('registers a booking taken at the counter without an account and mails the tickets', function (): void {
    $this->actingAs($this->owner)->post(route('ticketing.manage.guest', $this->date), ['names' => "Giulia Verdi\n\nMarco Neri", 'email' => 'giulia@example.test', 'note' => 'telefonata'])
        ->assertRedirect(route('ticketing.manage.show', $this->date));
    $booking = Booking::query()->whereNull('user_id')->firstOrFail();
    expect($booking->isStaffBooking())->toBeTrue()
        ->and($booking->created_by)->toBe($this->owner->id)
        ->and($booking->tickets)->toHaveCount(2)
        ->and($booking->booker_data['email'])->toBe('giulia@example.test')
        ->and($booking->contactEmail())->toBe('giulia@example.test');
    Notification::assertSentTo(new AnonymousNotifiable, GuestBookingConfirmation::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'giulia@example.test');
    expect($this->service->availability($this->date)['remaining'])->toBe(2);
    $this->actingAs($this->owner)->postJson('/api/v1/ticketing/'.$this->date->id.'/guest-booking', ['names' => "A\nB\nC"])
        ->assertUnprocessable()->assertJsonPath('error.fields.ticketing.0', __('ticketing.errors.full'));
    expect($this->actingAs($this->owner)->get(route('ticketing.manage.export', $this->date))->assertOk()->streamedContent())->toContain('giulia@example.test');
    $this->actingAs($this->user)->post(route('ticketing.manage.guest', $this->date), ['names' => 'Intruso'])->assertForbidden();
    expect($this->actingAs($this->owner)->get(route('ticketing.manage.show', $this->date))->getContent())->toContain(__('ticketing.guest.badge'));
});

it('marks no-shows only after a checked-in date has ended and blocks repeat offenders', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['Ada', 'Bea'], (string) Str::uuid(), false);
    $other = User::factory()->create();
    $this->service->reserve($other, $this->date, ['Cri'], (string) Str::uuid(), false);
    $this->travelTo(now()->setTime(19, 0));
    $this->service->checkIn($this->date, $booking->tickets->first()->code, $this->owner);
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(2, 0));
    expect($this->service->markNoShows())->toBe(2);
    expect($booking->tickets()->where('status', AdmissionStatus::NoShow)->count())->toBe(1)
        ->and(AdmissionTicket::query()->whereHas('booking', fn ($q) => $q->where('user_id', $other->id))->first()->status)->toBe(AdmissionStatus::NoShow)
        ->and($this->service->noShowCount($this->user))->toBe(1)
        ->and($this->service->statistics($this->date)['no_show'])->toBe(2);
    expect($this->actingAs($this->user)->get(route('tickets.index'))->getContent())->toContain('1 prenotazioni recenti');
    // A staff booking scanned alone is not proof the door was used.
    $staffOnly = occurrenceAt(testCity(), testCategory(), '2026-10-23 18:00:00', '2026-10-23 22:00:00', ['booking_enabled' => true]);
    $staffOnly->event->venue->update(['ticketing_enabled' => true]);
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
    $this->service->reserve(User::factory()->create(), $staffOnly, ['Gio'], (string) Str::uuid(), false);
    $guest = $this->service->reserveForGuest($staffOnly, ['Banco'], [], $this->owner);
    $this->travelTo(now()->setTime(19, 0));
    $this->service->checkIn($staffOnly, $guest->tickets->first()->code, $this->owner);
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(2, 0));
    expect($this->service->markNoShows())->toBe(0);
    // A date where nobody was scanned produces no absentees.
    $quiet = occurrenceAt(testCity(), testCategory(), '2026-10-23 18:00:00', '2026-10-23 22:00:00', ['booking_enabled' => true]);
    $quiet->event->venue->update(['ticketing_enabled' => true]);
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
    $this->service->reserve(User::factory()->create(), $quiet, ['Dan'], (string) Str::uuid(), false);
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(2, 0));
    expect($this->service->markNoShows())->toBe(0);
    // Recent absences at the same venue close its free bookings for that person
    // (the platform-wide block, capped per venue, is in NoShowTenantCapTest).
    config()->set('ticketing.no_show.limit', 1);
    $future = occurrenceAt(testCity(), testCategory(), '2026-10-30 18:00:00', null, ['booking_enabled' => true], [], $this->date->event->venue);
    expect(fn () => $this->service->reserve($this->user, $future, ['Ada'], (string) Str::uuid(), false))
        ->toThrow(ValidationException::class, __('ticketing.errors.no_show_limit_venue'));
    $this->service->reserve(User::factory()->create(), $future, ['Zoe'], (string) Str::uuid(), false);
});

it('serves a hashed manifest to door staff and accepts the original scan time from an offline read', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['Ada'], (string) Str::uuid(), false);
    $ticket = $booking->tickets->first();
    $second = $this->service->reserve(User::factory()->create(), $this->date, ['Bea'], (string) Str::uuid(), false)->tickets->first();
    $staff = User::factory()->create();
    $this->date->checkinStaff()->attach($staff);
    $response = $this->actingAs($staff)->getJson(route('ticketing.manage.manifest', $this->date))->assertOk();
    expect($response->json('data.tickets.0.h'))->toBe(hash('sha256', $ticket->code))
        ->and($response->json('data.tickets.0.name'))->toBe('Ada')
        ->and($response->getContent())->not->toContain($ticket->code);
    $this->actingAs($this->user)->getJson(route('ticketing.manage.manifest', $this->date))->assertForbidden();
    $this->travelTo(now()->setTime(20, 0));
    $this->actingAs($staff)->postJson(route('ticketing.manage.checkin', $this->date), ['code' => $ticket->code, 'request_key' => (string) Str::uuid(), 'scanned_at' => '2026-10-23T19:30:00+02:00'])
        ->assertOk();
    expect($ticket->fresh()->checked_in_at->toIso8601String())->toBe('2026-10-23T17:30:00+00:00');
    // A clock from before the doors opened is ignored: the server time wins.
    $this->actingAs($staff)->postJson(route('ticketing.manage.checkin', $this->date), ['code' => $second->code, 'scanned_at' => '2026-10-20T10:00:00+02:00'])->assertOk();
    expect($second->fresh()->checked_in_at->toIso8601String())->toBe(now()->toIso8601String());
    expect($this->actingAs($staff)->getJson(route('ticketing.manage.manifest', $this->date))->json('data.tickets.0.status'))->toBe('checked_in');
});
