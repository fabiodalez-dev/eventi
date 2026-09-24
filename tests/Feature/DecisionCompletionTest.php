<?php

use App\Actions\Account\FollowSubject;
use App\DTOs\NotificationMessage;
use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Enums\FollowableType;
use App\Enums\FollowNotificationMode;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationType;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Jobs\DeactivateWalletPass;
use App\Models\SavedEvent;
use App\Models\ScheduledNotification;
use App\Models\Sponsorship;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Services\Notifications\MessageFactory;
use App\Services\Notifications\NotificationScheduler;
use App\Services\Seo\EditorialContent;
use App\Services\Seo\PublicOffers;
use App\Services\Sponsorship\CampaignEconomics;
use App\Services\Ticketing\GoogleWallet;
use App\Services\Ticketing\TicketingService;
use App\Support\Capacity;
use App\Support\DeclaredCosts;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 9, 24)->setTime(15, 0));
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    $this->date = occurrenceAt($this->city, testCategory(), '2026-09-24 17:00:00', '2026-09-24 21:00:00', ['booking_enabled' => true, 'booking_capacity' => 10, 'booking_limit' => 20]);
    $this->date->event->venue->update(['ticketing_enabled' => true]);
    $this->user = User::factory()->create();
    $this->owner = User::factory()->create();
    $this->date->event->venue->members()->attach($this->owner, ['role' => 'owner']);
    $this->service = app(TicketingService::class);
});

it('does not silently turn unknown practical facts into a negative and resolves date overrides', function (): void {
    $this->date->event->venue->update(['content_details' => ['parking_notes' => 'Parcheggio del locale', 'accessibility' => 'yes']]);
    $this->date->event->update(['content_details' => ['food_notes' => 'Cucina fino alle 22']]);
    $this->date->update(['practical_details' => ['accessibility' => 'unknown', 'parking_notes' => 'Parcheggio chiuso per questa data']]);
    $details = app(EditorialContent::class)->details($this->date->event->fresh(), $this->date->fresh());
    expect($details['parking_notes'])->toBe('Parcheggio chiuso per questa data')
        ->and($details['food_notes'])->toBe('Cucina fino alle 22')
        ->and(array_column($details['practical_items'], 'label'))->toContain(__('decision.accessibility_unknown'), __('decision.transit_unknown'))
        ->not->toContain(__('decision.accessible'));
});

it('distinguishes zero and omitted costs and adds cents without floating point drift', function (): void {
    expect(DeclaredCosts::for($this->date))->toBeNull();
    $this->date->update(['cost_breakdown' => ['admission' => '10.10', 'drink' => '2.20', 'membership' => 0]]);
    $costs = DeclaredCosts::for($this->date);
    expect($costs['total_cents'])->toBe(1230)->and($costs['complete'])->toBeFalse()->and($costs['items'])->toHaveCount(3);
    $this->date->update(['cost_breakdown' => [...$this->date->cost_breakdown, 'other' => 0]]);
    expect(DeclaredCosts::for($this->date)['complete'])->toBeTrue();
});

it('uses real bookings instead of a stale manual counter and never divides by zero', function (): void {
    $this->date->update(['capacity_left' => 100]);
    $this->service->reserve($this->user, $this->date, ['Ada'], (string) Str::uuid(), false);
    expect(Capacity::for($this->date)->left)->toBe(9);
    $this->date->update(['booking_capacity' => null]);
    expect(Capacity::for($this->date))->toBeNull();
    $this->date->update(['booking_capacity' => 0]);
    expect(Capacity::for($this->date)->percentSold())->toBeNull();
});

it('queues almost full once and suppresses it if capacity becomes unknown before delivery', function (): void {
    SavedEvent::create(['user_id' => $this->user->id, 'occurrence_id' => $this->date->id]);
    $booker = User::factory()->create();
    $this->service->reserve($booker, $this->date, array_fill(0, 9, 'Ada'), (string) Str::uuid(), false);
    $scheduler = app(NotificationScheduler::class);
    $scheduler->announceAlmostFull($this->date);
    expect(ScheduledNotification::where('type', NotificationType::EventAlmostFull->value)->count())->toBe(1)
        ->and(NotificationType::EventAlmostFull->countsTowardDailyCap())->toBeTrue();
    $notice = ScheduledNotification::where('type', NotificationType::EventAlmostFull->value)->firstOrFail();
    expect(app(MessageFactory::class)->build($notice, $this->user))->toBeInstanceOf(NotificationMessage::class);
    $this->date->update(['booking_capacity' => null]);
    expect(app(MessageFactory::class)->build($notice->fresh(), $this->user))->toBe(NotificationSkipReason::NothingToSend);
});

it('excludes unknown capacity from almost full even with a manual remaining value', function (): void {
    $this->date->update(['booking_capacity' => null, 'capacity_left' => 1]);
    SavedEvent::create(['user_id' => $this->user->id, 'occurrence_id' => $this->date->id]);
    expect(app(NotificationScheduler::class)->announceAlmostFull($this->date))->toBe(0);
});

it('keeps new-only follows in the feed and first announcements but out of recurring digests', function (): void {
    $venue = $this->date->event->venue;
    $this->actingAs($this->user)->postJson('/api/v1/me/follows', ['type' => 'venue', 'id' => $venue->id, 'notification_mode' => 'new_only'])
        ->assertCreated()->assertJsonPath('data.notification_mode', 'new_only');
    expect(EventOccurrenceQuery::for($this->city)->followedBy($this->user)->get())->toHaveCount(1)
        ->and(EventOccurrenceQuery::for($this->city)->followedBy($this->user, notifyingOnly: true)->get())->toHaveCount(0);
    expect(app(NotificationScheduler::class)->announceToVenueFollowers($this->date->event))->toBe(1);
    app(FollowSubject::class)($this->user, FollowableType::Venue, $venue->id, mode: FollowNotificationMode::None);
    $notice = ScheduledNotification::where('type', NotificationType::VenueNewEvent->value)->firstOrFail();
    expect(app(MessageFactory::class)->build($notice, $this->user))->toBe(NotificationSkipReason::PreferenceOff);
});

it('returns explicit feed explanations and a way to see the whole catalogue', function (): void {
    app(FollowSubject::class)($this->user, FollowableType::Venue, $this->date->event->venue_id);
    $this->actingAs($this->user)->getJson('/api/v1/me/feed')->assertOk()->assertJsonPath('data.0.recommendation_reasons.0', __('decision.because_venue'));
    $this->get(route('account.feed'))->assertOk()->assertSee(__('decision.because_venue'))->assertSee(__('decision.show_all'));
});

it('limits last hours to the temporal engine window in chronological order including sold out dates', function (): void {
    $earlier = occurrenceAt($this->city, $this->date->event->category, '2026-09-24 16:00:00', null, ['status' => OccurrenceStatus::SoldOut]);
    occurrenceAt($this->city, $this->date->event->category, '2026-09-24 18:00:01');
    expect(EventOccurrenceQuery::for($this->city)->lastHours()->get()->modelKeys())->toBe([$earlier->id, $this->date->id]);
    $this->get('/eventi?date=last_hours')->assertOk();
    $this->getJson('/api/v1/events?preset=last_hours&sort=popular')->assertOk()->assertJsonPath('data.0.occurrence_id', $earlier->id);
});

it('gives door staff access only to the assigned scanner and revokes it immediately', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['Ada'], (string) Str::uuid(), false);
    $staff = User::factory()->create();
    $this->actingAs($this->owner)->post(route('ticketing.manage.staff', $this->date), ['email' => $staff->email])->assertRedirect();
    $this->actingAs($staff)->get(route('ticketing.manage.scanner', $this->date))->assertOk()->assertDontSee($this->user->email);
    $this->get(route('ticketing.manage.show', $this->date))->assertForbidden();
    $this->get(route('ticketing.manage.export', $this->date))->assertForbidden();
    $this->post(route('ticketing.manage.staff', $this->date), ['email' => $staff->email])->assertForbidden();
    $this->postJson(route('ticketing.manage.checkin', $this->date), ['code' => $booking->tickets->first()->code, 'request_key' => (string) Str::uuid()])->assertOk();
    $this->actingAs($this->owner)->post(route('ticketing.manage.staff', $this->date), ['email' => $staff->email, 'remove' => true])->assertRedirect();
    $this->actingAs($staff)->get(route('ticketing.manage.scanner', $this->date))->assertForbidden();
});

it('makes a lost successful checkin reply retryable without accepting a second independent scan', function (): void {
    $booking = $this->service->reserve($this->user, $this->date, ['Ada'], (string) Str::uuid(), false);
    $code = $booking->tickets->first()->code;
    $key = (string) Str::uuid();
    $this->actingAs($this->owner)->postJson(route('ticketing.manage.checkin', $this->date), ['code' => $code, 'request_key' => $key])->assertOk();
    $this->postJson(route('ticketing.manage.checkin', $this->date), ['code' => $code, 'request_key' => $key])->assertOk();
    $this->postJson(route('ticketing.manage.checkin', $this->date), ['code' => $code, 'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('ticketing');
    expect($booking->tickets()->where('status', AdmissionStatus::CheckedIn)->count())->toBe(1);
});

it('synchronizes only saved dates independently of hidden discovery categories and removes cancellations', function (): void {
    SavedEvent::create(['user_id' => $this->user->id, 'occurrence_id' => $this->date->id]);
    $this->user->update(['content_preferences' => ['mode' => 'selected', 'categories' => []]]);
    $this->actingAs($this->user)->getJson('/api/v1/me/calendar/export?saved_only=1&days=90')->assertOk()->assertJsonCount(1, 'data.events');
    $this->date->update(['starts_at' => '2026-09-25 17:00:00', 'ends_at' => '2026-09-25 21:00:00']);
    $this->getJson('/api/v1/me/calendar/export?saved_only=1&days=90')->assertOk()->assertJsonPath('data.events.0.start', $this->date->starts_at->getTimestamp() * 1000);
    $this->date->update(['status' => OccurrenceStatus::Cancelled]);
    $this->getJson('/api/v1/me/calendar/export?saved_only=1&days=90')->assertOk()->assertJsonCount(0, 'data.events');
});

it('deactivates issued wallet passes for partial cancellation without affecting other tickets', function (): void {
    Queue::fake();
    config(['wallet.google.issuer_id' => 'issuer', 'wallet.google.class_id' => 'class', 'wallet.google.service_account_email' => 'service@example.test', 'wallet.google.private_key' => 'unused']);
    $booking = $this->service->reserve($this->user, $this->date, ['Ada', 'Eva'], (string) Str::uuid(), false);
    $ticket = $booking->tickets->first();
    $ticket->update(['wallet_requested_at' => now()]);
    $this->service->cancel($booking, $this->user, $ticket->id);
    Queue::assertPushed(DeactivateWalletPass::class, fn ($job) => $job->objectId === 'issuer.incitta-'.$ticket->id);
    Queue::assertPushed(DeactivateWalletPass::class, 1);
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('patches wallet state and propagates provider failures for queued retries', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    config(['wallet.google.service_account_email' => 'service@example.test', 'wallet.google.private_key' => $pem]);
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake']), 'walletobjects.googleapis.com/*' => Http::sequence()->push([], 200)->push([], 503)]);
    app(GoogleWallet::class)->deactivate('issuer.incitta-42');
    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['state'] === 'INACTIVE');
    expect(fn () => app(GoogleWallet::class)->deactivate('issuer.incitta-42'))->toThrow(RequestException::class);
});

it('calculates period ratios only above the privacy threshold and never invents missing spend', function (): void {
    $campaign = Sponsorship::factory()->create(['event_id' => $this->date->event_id, 'city_id' => $this->city->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'amount_cents' => 10000]);
    $report = app(CampaignEconomics::class);
    expect($report->for($campaign)['per_booking_cents'])->toBeNull();
    foreach (range(1, 5) as $i) {
        $booking = $this->service->reserve(User::factory()->create(), $this->date, ['Ada'], (string) Str::uuid(), false);
        $this->service->checkIn($this->date, $booking->tickets->first()->code, $this->owner);
    }
    expect($report->for($campaign)['per_booking_cents'])->toBe(2000)->and($report->for($campaign)['per_attendance_cents'])->toBe(2000);
    $campaign->update(['amount_cents' => null]);
    expect($report->for($campaign)['per_booking_cents'])->toBeNull();
});

it('filters by complete mandatory costs instead of the parent price and excludes partial totals', function (): void {
    $this->date->event->update(['price_type' => PriceType::Ticket, 'price_min' => 50]);
    $this->date->update(['cost_breakdown' => ['admission' => 0, 'drink' => 0, 'membership' => 0, 'other' => 0]]);
    expect(EventOccurrenceQuery::for($this->city)->priceFree()->get()->modelKeys())->toContain($this->date->id)
        ->and(EventOccurrenceQuery::for($this->city)->pricePaid()->get())->toHaveCount(0);
    $this->date->update(['cost_breakdown' => ['admission' => 5, 'drink' => 2, 'membership' => 0, 'other' => 0]]);
    expect(EventOccurrenceQuery::for($this->city)->priceMax(7)->get()->modelKeys())->toContain($this->date->id)
        ->and(EventOccurrenceQuery::for($this->city)->priceMax(6)->get())->toHaveCount(0);
    expect(EventOccurrenceQuery::for($this->city)->pricePaid()->get()->modelKeys())->toContain($this->date->id);
    $this->date->update(['cost_breakdown' => ['admission' => 0]]);
    expect(EventOccurrenceQuery::for($this->city)->priceFree()->get())->toHaveCount(0);
});

it('does not advertise a full booking as available when complete costs are declared', function (): void {
    $this->date->update(['booking_capacity' => 0, 'cost_breakdown' => array_fill_keys(DeclaredCosts::FIELDS, 0)]);
    $offers = app(PublicOffers::class)->for($this->date->event, $this->date);
    expect($offers[0]['availability'])->toBe('https://schema.org/SoldOut');
});

it('creates a provider object before issuing a wallet link and rejects cancelled tickets', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    config(['wallet.google.issuer_id' => 'issuer', 'wallet.google.class_id' => 'class', 'wallet.google.service_account_email' => 'service@example.test', 'wallet.google.private_key' => $pem]);
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake']), 'walletobjects.googleapis.com/*' => Http::response([], 200)]);
    $booking = $this->service->reserve($this->user, $this->date, ['Ada'], (string) Str::uuid(), false);
    $ticket = $booking->tickets->first();
    $response = $this->actingAs($this->user)->postJson('/api/v1/me/tickets/'.$ticket->id.'/wallet')->assertOk();
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'eventTicketObject')
        && $request['id'] === 'issuer.incitta-'.$ticket->id && isset($request['textModulesData']) && ! isset($request['eventName']));
    expect($ticket->fresh()->wallet_requested_at)->not->toBeNull();
    Queue::fake();
    $this->service->cancel($booking, $this->user);
    $this->postJson('/api/v1/me/tickets/'.$ticket->id.'/wallet')->assertNotFound();
});

it('does not expose attendance ratios from one large group belonging to a single account', function (): void {
    $campaign = Sponsorship::factory()->create(['event_id' => $this->date->event_id, 'city_id' => $this->city->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'amount_cents' => 10000]);
    $booking = $this->service->reserve($this->user, $this->date, array_fill(0, 5, 'Ada'), (string) Str::uuid(), false);
    foreach ($booking->tickets as $ticket) {
        $this->service->checkIn($this->date, $ticket->code, $this->owner);
    }
    $report = app(CampaignEconomics::class)->for($campaign);
    expect($report['attendances'])->toBeNull()->and($report['per_attendance_cents'])->toBeNull();
});

it('does not announce a wholly empty small venue or show closed bookings as available', function (): void {
    $this->date->update(['booking_capacity' => 1]);
    expect(Capacity::for($this->date)->isAlmostFull())->toBeFalse();
    $this->date->update(['status' => OccurrenceStatus::SoldOut]);
    expect(Capacity::for($this->date)->left)->toBe(0);
    $this->date->update(['status' => OccurrenceStatus::Scheduled, 'booking_closes_at' => now()->subMinute()]);
    expect(Capacity::for($this->date))->toBeNull();
});
