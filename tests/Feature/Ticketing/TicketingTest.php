<?php

use App\Actions\Account\DeleteAccount;
use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Http\Resources\V1\BookingResource;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingChanged;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 9, 5)->setTime(12, 0));
    (new RolesAndPermissionsSeeder)->run();
    $this->date = occurrenceAt(testCity(), testCategory(), '2026-09-06 18:00:00', '2026-09-06 22:00:00', ['booking_enabled' => true, 'booking_capacity' => 2, 'booking_waitlist' => true]);
    $this->date->event->venue->update(['ticketing_enabled' => true]);
    $this->user = User::factory()->create();
    $this->service = app(TicketingService::class);
    $this->payload = ['attendees' => ['Giulia Rossi'], 'request_key' => (string) Str::uuid(), 'accept_terms' => true];
});

it('requires authentication and explicit consent', function (): void {
    $this->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $this->payload)->assertUnauthorized();
    $this->actingAs($this->user)->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', [...$this->payload, 'accept_terms' => false])->assertUnprocessable();
});

it('creates the standalone demo without faker or changing existing accounts', function (): void {
    $this->artisan('ticketing:demo --force')->assertSuccessful();
    $venue = Venue::where('slug', 'spazio-delle-erbe')->firstOrFail();
    $reader = User::where('email', 'biglietti@example.test')->firstOrFail();
    $originalPassword = $reader->password;
    expect($venue->events()->count())->toBe(3)
        ->and(Booking::where('user_id', $reader->id)->count())->toBe(3);
    $this->artisan('ticketing:demo --force')->assertSuccessful();
    expect($venue->events()->count())->toBe(3)
        ->and($reader->fresh()->password)->toBe($originalPassword)
        ->and(Booking::where('user_id', $reader->id)->count())->toBe(3);
});

it('collects configured booker data with separate names and records privacy privately', function (): void {
    $this->date->update(['booking_fields' => ['address' => 'required', 'phone' => 'optional', 'city' => 'hidden']]);
    $payload = [...$this->payload, 'attendees' => [['first_name' => 'Anna Maria', 'last_name' => 'De Rossi']], 'booker' => ['first_name' => 'Giulia', 'last_name' => 'Rossi', 'address' => 'Via Test 42', 'city' => 'Must not be stored']];
    $response = $this->actingAs($this->user)->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $payload)->assertCreated();
    $booking = Booking::findOrFail($response->json('data.id'));
    expect($booking->tickets->first()->first_name)->toBe('Anna Maria')
        ->and($booking->tickets->first()->last_name)->toBe('De Rossi')
        ->and($booking->booker_data['address'])->toBe('Via Test 42')
        ->and($booking->booker_data)->not->toHaveKey('city')
        ->and($booking->privacy_accepted_at)->not->toBeNull()
        ->and($booking->getRawOriginal('booker_data'))->not->toContain('Via Test');
    $this->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $payload)->assertCreated()->assertJsonPath('data.id', $booking->id);
    $payload['booker']['address'] = 'Another address';
    $this->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $payload)->assertUnprocessable();
});

it('enforces configured required fields for all clients without occupying seats', function (): void {
    $this->date->update(['booking_fields' => ['address' => 'required']]);
    $this->actingAs($this->user)->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $this->payload)->assertUnprocessable();
    expect(Booking::count())->toBe(0);
});

it('requires both name parts and a booker for structured reservations', function (): void {
    $payload = [...$this->payload, 'attendees' => [['first_name' => 'Anna', 'last_name' => '']]];
    $this->actingAs($this->user)->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $payload)->assertUnprocessable();
    $payload['attendees'][0]['last_name'] = 'Rossi';
    $this->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $payload)->assertUnprocessable();
    expect(Booking::count())->toBe(0);
});

it('attaches actual PDF tickets to confirmation and resend but excludes revoked tickets', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['A', 'B'], (string) Str::uuid(), false);
    $mail = (new BookingChanged($booking->id, 'confirmed'))->toMail($this->user);
    expect($mail->rawAttachments)->toHaveCount(2)
        ->and($mail->rawAttachments[0]['data'])->toStartWith('%PDF-')
        ->and($mail->rawAttachments[0]['options']['mime'])->toBe('application/pdf');
    $this->service->cancel($booking, $this->user, $booking->tickets[0]->id);
    expect((new BookingChanged($booking->id, 'confirmed'))->toMail($this->user)->rawAttachments)->toHaveCount(1);
    $this->service->cancel($booking->fresh(), $this->user);
    expect((new BookingChanged($booking->id, 'confirmed'))->toMail($this->user)->rawAttachments)->toHaveCount(0);
});

it('allows only the owning venue to configure field collection', function (): void {
    $other = User::factory()->create();
    $other->venues()->attach(Venue::factory()->create()->id, ['role' => 'owner']);
    $settings = ['booking_enabled' => 1, 'booking_limit' => 3, 'booking_waitlist' => 1, 'booking_fields' => ['address' => 'required']];
    $this->actingAs($other)->post('/gestione-biglietti/'.$this->date->id.'/impostazioni', $settings)->assertForbidden();
    $owner = User::factory()->create();
    $owner->venues()->attach($this->date->event->venue_id, ['role' => 'owner']);
    $this->actingAs($owner)->post('/gestione-biglietti/'.$this->date->id.'/impostazioni', $settings)->assertRedirect();
    expect($this->date->fresh()->booking_fields)->toBe(['address' => 'required']);
    $this->getJson('/api/v1/occurrences/'.$this->date->id.'/booking')->assertJsonPath('data.booker_fields.0.key', 'address')->assertJsonPath('data.booker_fields.0.required', true);
});

it('reserves privately and retries do not consume another seat', function (): void {
    $url = '/api/v1/occurrences/'.$this->date->id.'/bookings';
    $response = $this->actingAs($this->user)->postJson($url, $this->payload)->assertCreated()->assertJsonPath('data.status', 'confirmed');
    expect($response->json('data.tickets.0.qr_payload'))->toHaveLength(64);
    $this->postJson($url, $this->payload)->assertCreated()->assertJsonPath('data.id', $response->json('data.id'));
    expect(Booking::count())->toBe(1);
    $this->postJson($url, [...$this->payload, 'attendees' => ['Other']])->assertUnprocessable();
    $this->getJson('/api/v1/me/bookings')->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
});

it('never oversells and promotes the waiting group when a place is released', function (): void {
    $a = $this->service->reserve($this->user, $this->date, ['A', 'B'], (string) Str::uuid(), false);
    $other = User::factory()->create();
    $waiting = $this->service->reserve($other, $this->date, ['C'], (string) Str::uuid(), true);
    expect($waiting->status)->toBe(BookingStatus::Waitlisted);
    expect(BookingResource::toArray($waiting)['tickets'][0]['qr_payload'])->toBeNull();
    $this->service->cancel($a, $this->user, $a->tickets->first()->id);
    expect($waiting->fresh()->status)->toBe(BookingStatus::Confirmed);
    expect($this->service->availability($this->date)['remaining'])->toBe(0);
});

it('rejects a second reservation with another request key on web and API', function (): void {
    $this->date->update(['booking_capacity' => null, 'booking_limit' => 6]);
    $booking = $this->service->reserve($this->user, $this->date, ['A'], (string) Str::uuid(), false);
    $this->actingAs($this->user)->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $this->payload)
        ->assertUnprocessable()->assertJsonPath('error.fields.ticketing.0', __('ticketing.errors.already_booked'));
    $this->post('/biglietti/prenota/'.$this->date->id, $this->payload)->assertSessionHasErrors('ticketing');
    expect(Booking::count())->toBe(1)->and(AdmissionTicket::count())->toBe(1);
    $this->get('/eventi/'.$this->date->event->slug)->assertOk()
        ->assertSee(__('ticketing.manage_booking'))->assertSee(route('tickets.show', $booking), false)
        ->assertDontSee('href="'.route('tickets.create', $this->date).'"', false);
    $this->get(route('tickets.show', $booking))->assertOk()->assertSee('Mostra QR');
    $this->actingAs(User::factory()->create())->get(route('tickets.show', $booking))->assertForbidden();
    $this->get('/eventi/'.$this->date->event->slug)->assertOk()
        ->assertSee(route('tickets.create', $this->date), false)->assertDontSee(__('ticketing.manage_booking'));
});

it('blocks duplicate waitlists and partial cancellations but allows rebooking after full cancellation', function (): void {
    $this->date->update(['booking_capacity' => 0]);
    $booking = $this->service->reserve($this->user, $this->date, ['A', 'B'], (string) Str::uuid(), true);
    $url = '/api/v1/occurrences/'.$this->date->id.'/bookings';
    $payload = [...$this->payload, 'waitlist' => true];
    $this->actingAs($this->user)->postJson($url, $payload)->assertUnprocessable();
    $this->get('/biglietti/prenota/'.$this->date->id)->assertRedirect(route('tickets.show', $booking));
    $this->service->cancel($booking, $this->user, $booking->tickets[0]->id);
    $this->postJson($url, $payload)->assertUnprocessable();
    $this->service->cancel($booking->fresh(), $this->user);
    $this->get('/biglietti/prenota/'.$this->date->id)->assertOk();
    $this->get('/eventi/'.$this->date->event->slug)->assertSee(route('tickets.create', $this->date), false);
    $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.status', 'waitlisted');
    expect(Booking::query()->active()->count())->toBe(1);
});

it('allows separate dates of the same event', function (): void {
    $this->service->reserve($this->user, $this->date, ['A'], (string) Str::uuid(), false);
    $otherDate = $this->date->replicate();
    $otherDate->starts_at = $this->date->starts_at->copy()->addDay();
    $otherDate->ends_at = $this->date->ends_at->copy()->addDay();
    $otherDate->save();
    $this->service->reserve($this->user, $otherDate, ['A'], (string) Str::uuid(), false);
    expect(Booking::query()->active()->count())->toBe(2);
});

it('supports unlimited seats and enforces the account limit', function (): void {
    $this->date->update(['booking_capacity' => null, 'booking_limit' => 2]);
    $this->service->reserve($this->user, $this->date, ['A', 'B'], (string) Str::uuid(), false);
    expect($this->service->availability($this->date)['remaining'])->toBeNull();
    $this->actingAs($this->user)->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $this->payload)->assertUnprocessable();
});

it('does not expose or cancel another account tickets', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['A'], (string) Str::uuid(), false);
    $this->actingAs(User::factory()->create())->getJson('/api/v1/me/bookings')->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/me/bookings/'.$booking->id.'/cancel')->assertForbidden();
    $this->get('/biglietti/pdf/'.$booking->tickets->first()->id)->assertForbidden();
});

it('allows only owners of the specific venue to export or check in', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['A'], (string) Str::uuid(), false);
    $owner = User::factory()->create();
    $owner->venues()->attach($this->date->event->venue_id, ['role' => 'owner']);
    $this->actingAs($owner)->get('/gestione-biglietti/'.$this->date->id.'/csv')->assertOk();
    $this->get('/gestione-biglietti/'.$this->date->id)->assertOk();
    $this->actingAs(User::factory()->create())->get('/gestione-biglietti/'.$this->date->id.'/csv')->assertForbidden();
    $this->postJson('/api/v1/ticketing/'.$this->date->id.'/check-in', ['code' => $booking->tickets->first()->code])->assertForbidden();
});

it('rejects repeated and cancelled QR codes', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['A', 'B'], (string) Str::uuid(), false);
    $owner = User::factory()->create();
    $owner->venues()->attach($this->date->event->venue_id, ['role' => 'owner']);
    $this->service->cancel($booking, $this->user, $booking->tickets[1]->id);
    $this->travelTo($this->date->starts_at);
    $url = '/api/v1/ticketing/'.$this->date->id.'/check-in';
    $this->actingAs($owner)->postJson($url, ['code' => $booking->tickets[0]->code])->assertOk();
    $this->postJson($url, ['code' => $booking->tickets[0]->code])->assertUnprocessable();
    $this->postJson($url, ['code' => $booking->tickets[1]->code])->assertUnprocessable();
});

it('revokes all tickets when an occurrence is cancelled and never resurrects QR codes', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['A'], (string) Str::uuid(), false);
    $this->date->update(['status' => OccurrenceStatus::Cancelled]);
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($booking->tickets->first()->fresh()->status)->toBe(AdmissionStatus::Cancelled);
    $this->date->update(['status' => OccurrenceStatus::Scheduled]);
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('renders booking forms, QR profile and PDF', function (): void {
    $this->actingAs($this->user)->get('/biglietti/prenota/'.$this->date->id)->assertOk();
    $booking = $this->service->reserve($this->user, $this->date, ['A'], (string) Str::uuid(), false);
    $this->get('/biglietti/prenota/'.$this->date->id)->assertRedirect(route('tickets.show', $booking));
    $this->get('/biglietti')->assertOk()->assertSee('Mostra QR');
    $this->get('/biglietti/pdf/'.$booking->tickets->first()->id)->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('erases attendee data when deleting the account and frees seats', function (): void {
    $this->service->reserve($this->user, $this->date, ['Private name'], (string) Str::uuid(), false);
    app(DeleteAccount::class)($this->user);
    expect(Booking::count())->toBe(0)->and(AdmissionTicket::count())->toBe(0);
});

it('blocks new reservations when the venue disables ticketing but preserves cancellation', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['A'], (string) Str::uuid(), false);
    $this->date->event->venue->update(['ticketing_enabled' => false]);
    $this->actingAs($this->user)->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $this->payload)->assertUnprocessable();
    $this->postJson('/api/v1/me/bookings/'.$booking->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('preserves FIFO groups instead of letting newcomers bypass a waiting group', function (): void {
    $this->service->reserve($this->user, $this->date, ['A'], (string) Str::uuid(), false);
    $waiting = $this->service->reserve(User::factory()->create(), $this->date, ['B', 'C'], (string) Str::uuid(), true);
    $this->actingAs(User::factory()->create())->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $this->payload)->assertUnprocessable();
    expect($waiting->fresh()->status)->toBe(BookingStatus::Waitlisted);
});

it('checks opening closing and cancellation deadlines on the server', function (): void {
    $this->date->update(['booking_opens_at' => now()->addHour()]);
    $this->actingAs($this->user)->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $this->payload)->assertUnprocessable();
    $this->travel(2)->hours();
    $booking = $this->service->reserve($this->user, $this->date, ['A'], (string) Str::uuid(), false);
    $this->date->update(['cancellation_closes_at' => now()->subMinute()]);
    $this->postJson('/api/v1/me/bookings/'.$booking->id.'/cancel')->assertUnprocessable();
    $this->date->update(['booking_closes_at' => now()]);
    $this->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', $this->payload)->assertUnprocessable();
});

it('does not let an owner reduce inventory below issued tickets and converts local settings to UTC', function (): void {
    $owner = User::factory()->create();
    $owner->venues()->attach($this->date->event->venue_id, ['role' => 'owner']);
    $this->service->reserve($this->user, $this->date, ['A', 'B'], (string) Str::uuid(), false);
    $settings = ['booking_enabled' => true, 'booking_capacity' => 1, 'booking_limit' => 6, 'booking_waitlist' => true];
    $url = '/gestione-biglietti/'.$this->date->id.'/impostazioni';
    $this->actingAs($owner)->postJson($url, $settings)->assertUnprocessable();
    $this->post($url, [...$settings, 'booking_capacity' => null, 'booking_closes_at' => '2026-09-06T19:00'])->assertRedirect();
    expect($this->date->fresh()->booking_capacity)->toBeNull()
        ->and($this->date->fresh()->booking_closes_at->format('H:i'))->toBe('17:00');
});

it('denies editors and owners of other venues access to participants', function (): void {
    $editor = User::factory()->create();
    $editor->venues()->attach($this->date->event->venue_id, ['role' => 'editor']);
    $this->actingAs($editor)->get('/gestione-biglietti/'.$this->date->id)->assertForbidden();
    $owner = User::factory()->create();
    $owner->venues()->attach(Venue::factory()->approved()->create()->id, ['role' => 'owner']);
    $this->actingAs($owner)->get('/gestione-biglietti/'.$this->date->id.'/csv')->assertForbidden();
});

it('exports safe CSV without QR secrets', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['=SUM(1+1)'], (string) Str::uuid(), false);
    $owner = User::factory()->create();
    $owner->venues()->attach($this->date->event->venue_id, ['role' => 'owner']);
    $csv = $this->actingAs($owner)->get('/gestione-biglietti/'.$this->date->id.'/csv')->assertOk()->streamedContent();
    expect($csv)->toContain("'=SUM(1+1)")->not->toContain($booking->tickets->first()->code);
});

it('revokes tickets on whole event cancellation and notifies attendees about time changes', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['A'], (string) Str::uuid(), false);
    $this->date->update(['starts_at' => $this->date->starts_at->copy()->addMinutes(15)]);
    Notification::assertSentTo($this->user, BookingChanged::class, fn ($notification) => $notification->kind === 'changed');
    $this->date->event->update(['status' => EventStatus::Cancelled]);
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('hides expired QR and allows only the booking owner to resend a summary', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['A'], (string) Str::uuid(), false);
    $this->actingAs(User::factory()->create())->postJson('/api/v1/me/bookings/'.$booking->id.'/email')->assertForbidden();
    $this->actingAs($this->user)->postJson('/api/v1/me/bookings/'.$booking->id.'/email')->assertOk();
    $this->travelTo($this->date->effective_ends_at->copy()->addMinute());
    $this->getJson('/api/v1/me/bookings')->assertJsonPath('data.0.tickets.0.status', 'expired')->assertJsonPath('data.0.tickets.0.qr_payload', null);
});
