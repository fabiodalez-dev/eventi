<?php

declare(strict_types=1);

use App\Enums\AdmissionStatus;
use App\Models\AvailabilityAlert;
use App\Models\PresenceShare;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\TicketShared;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

afterEach(fn () => Carbon\Carbon::setTestNow());

beforeEach(function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    $this->category = testCategory();
    $this->user = User::factory()->create(['email_verified_at' => now(), 'first_name' => 'Giulia']);
    freezeLocal($this->city, '2026-10-23 12:00');
});

it('gives each valid ticket a personal signed link and sends by email only with consent and without storing the address', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', '2026-10-30 23:00', ['booking_enabled' => true]);
    $date->event->venue->update(['ticketing_enabled' => true]);
    $booking = app(TicketingService::class)->reserve($this->user, $date, ['Giulia', 'Marco'], (string) Str::uuid(), false);
    $api = $this->actingAs($this->user)->getJson('/api/v1/me/bookings')->assertOk();
    $url = $api->json('data.0.tickets.1.share_url');
    expect($url)->toContain('/biglietto/')->toContain('signature=');
    $this->app['auth']->forgetGuards();
    $this->get($url)->assertOk()->assertSee('Marco')->assertSee('role="img"', false);
    $this->get(route('tickets.shared', $booking->tickets[1]))->assertForbidden();
    $marco = $booking->tickets[1];
    $this->actingAs($this->user)->postJson('/api/v1/me/tickets/'.$marco->id.'/send', ['email' => 'marco@example.test'])->assertUnprocessable();
    $this->actingAs($this->user)->postJson('/api/v1/me/tickets/'.$marco->id.'/send', ['email' => 'marco@example.test', 'consent' => true])->assertOk();
    Notification::assertSentTo(new AnonymousNotifiable, TicketShared::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'marco@example.test');
    $this->actingAs($this->user)->postJson('/api/v1/me/tickets/'.$marco->id.'/send', ['email' => 'marco@example.test', 'consent' => true])->assertUnprocessable();
    expect(json_encode(Activity::query()->where('description', 'ticket_shared')->get()->toArray()))->not->toContain('marco@example.test');
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->postJson('/api/v1/me/tickets/'.$marco->id.'/send', ['email' => 'x@example.test', 'consent' => true])->assertForbidden();
    app(TicketingService::class)->cancel($booking, $this->user, $marco->id);
    $this->app['auth']->forgetGuards();
    $this->get($url)->assertOk()->assertDontSee('role="img"', false)->assertSee(__('ticketing.statuses.cancelled'));
});

it('alerts once when a sold-out date becomes available again', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', null, ['status' => 'sold_out']);
    $free = occurrenceAtLocal($this->city, $this->category, '2026-10-31 21:00');
    $this->actingAs($this->user)->get(route('events.show', $date->event))->assertOk()->assertSee(__('ticketing.availability.subscribe'));
    $this->actingAs($this->user)->postJson('/api/v1/me/availability-alerts/'.$free->id)->assertUnprocessable();
    $this->actingAs($this->user)->postJson('/api/v1/me/availability-alerts/'.$date->id)->assertCreated();
    expect($this->actingAs($this->user)->getJson('/api/v1/me/availability-alerts')->json('data'))->toBe([$date->id]);
    $date->update(['status' => 'scheduled']);
    expect(ScheduledNotification::query()->ofType('tickets_available')->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(AvailabilityAlert::query()->count())->toBe(0);
});

it('lists the evenings attended with a checked-in ticket and shares a temporary «I am here» link', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-10-23 18:00', '2026-10-23 22:00', ['booking_enabled' => true]);
    $date->event->venue->update(['ticketing_enabled' => true]);
    $owner = User::factory()->create();
    $date->event->venue->members()->attach($owner, ['role' => 'owner']);
    $booking = app(TicketingService::class)->reserve($this->user, $date, ['Giulia'], (string) Str::uuid(), false);
    $this->actingAs($this->user)->post(route('presence.store', $date->id), ['display_name' => 'Giulia', 'note' => 'Al bancone'])->assertRedirect();
    $share = PresenceShare::query()->sole();
    $this->app['auth']->forgetGuards();
    $this->get(route('presence.show', $share->token))->assertOk()->assertSee('Giulia è qui')->assertSee('Al bancone')->assertSee($date->event->venue->name);
    $api = $this->actingAs($this->user)->postJson('/api/v1/me/presence', ['occurrence_id' => $date->id])->assertCreated();
    expect($api->json('data.url'))->toContain('/sono-qui/');
    expect($share->fresh()->revoked_at)->not->toBeNull();
    $this->actingAs($this->user)->deleteJson('/api/v1/me/presence/'.$api->json('data.token'))->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->get($api->json('data.url'))->assertNotFound();
    $later = occurrenceAtLocal($this->city, $this->category, '2026-11-05 21:00');
    $this->actingAs($this->user)->postJson('/api/v1/me/presence', ['occurrence_id' => $later->id])->assertUnprocessable();

    freezeLocal($this->city, '2026-10-23 19:00');
    app(TicketingService::class)->checkIn($date, $booking->tickets->first()->code, $owner);
    freezeLocal($this->city, '2026-10-25 12:00');
    $attended = $this->actingAs($this->user)->getJson('/api/v1/me/attended')->assertOk();
    expect($attended->json('data'))->toHaveCount(1)
        ->and($attended->json('data.0.occurrence_id'))->toBe($date->id)
        ->and($attended->json('data.0.checked_in'))->toBe(1)
        ->and($attended->json('data.0.venue.slug'))->toBe($date->event->venue->slug);
    $this->actingAs($this->user)->get(route('account.attended'))->assertOk()->assertSee($date->event->title);
    expect(AdmissionStatus::CheckedIn->value)->toBe('checked_in');
});

it('refuses alerts, «I am here» links and hidden venues on content that is not public', function (): void {
    $draft = occurrenceAtLocal($this->city, $this->category, '2026-10-23 18:00', '2026-10-23 22:00', ['status' => 'sold_out'], ['status' => 'draft']);
    $this->actingAs($this->user)->postJson('/api/v1/me/availability-alerts/'.$draft->id)->assertNotFound();
    $this->actingAs($this->user)->postJson('/api/v1/me/presence', ['occurrence_id' => $draft->id])->assertNotFound();
    $hiddenVenue = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'draft']);
    $this->actingAs($this->user)->postJson('/api/v1/me/hidden-venues/'.$hiddenVenue->id)->assertNotFound();
    $this->actingAs($this->user)->patchJson('/api/v1/me/content-preferences', ['mode' => 'all', 'categories' => [], 'hidden_categories' => [], 'inferred_ads' => true, 'hidden_venues' => [$hiddenVenue->id]])->assertUnprocessable();
});
