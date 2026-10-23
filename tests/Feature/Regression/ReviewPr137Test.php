<?php

declare(strict_types=1);

use App\Actions\MergeEventsAction;
use App\Enums\AdmissionStatus;
use App\Enums\UserRole;
use App\Models\AdmissionTicket;
use App\Models\Event;
use App\Models\EventCollection;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Services\Search\SavedSearchMatcher;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

afterEach(fn () => Carbon\Carbon::setTestNow());

beforeEach(function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    $this->category = testCategory(['slug' => 'musica-dal-vivo']);
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('accepts an offline scan synced after the door closed when the scan itself was inside the evening', function (): void {
    freezeLocal($this->city, '2026-10-30 12:00');
    $date = occurrenceAtLocal($this->city, $this->category, '2026-10-30 20:00', '2026-10-30 22:00', ['booking_enabled' => true]);
    $date->event->venue->update(['ticketing_enabled' => true]);
    $owner = User::factory()->create();
    $date->event->venue->members()->attach($owner, ['role' => 'owner']);
    $ticket = app(TicketingService::class)->reserve($this->user, $date, ['Ada'], (string) Str::uuid(), false)->tickets->first();
    freezeLocal($this->city, '2026-10-30 23:00');
    $scanned = localInstant($this->city, '2026-10-30 21:30');
    app(TicketingService::class)->checkIn($date, $ticket->code, $owner, (string) Str::uuid(), $scanned);
    expect($ticket->fresh()->status)->toBe(AdmissionStatus::CheckedIn)
        ->and($ticket->fresh()->checked_in_at->equalTo($scanned))->toBeTrue();
    // Without an offline scan time, after the door closed is still closed.
    $other = app(TicketingService::class);
    freezeLocal($this->city, '2026-10-30 12:00');
    $second = $other->reserve(User::factory()->create(), $date, ['Bea'], (string) Str::uuid(), false)->tickets->first();
    freezeLocal($this->city, '2026-10-30 23:00');
    expect(fn () => $other->checkIn($date, $second->code, $owner))->toThrow(ValidationException::class);
    // And a scan time outside the evening does not open the door either.
    expect(fn () => $other->checkIn($date, $second->code, $owner, null, localInstant($this->city, '2026-10-30 23:00')))->toThrow(ValidationException::class);
});

it('counts absences by the date of the evening, not by when the command marked them', function (): void {
    freezeLocal($this->city, '2025-09-25 12:00');
    $old = occurrenceAtLocal($this->city, $this->category, '2025-10-01 20:00', '2025-10-01 22:00', ['booking_enabled' => true]);
    $old->event->venue->update(['ticketing_enabled' => true]);
    $owner = User::factory()->create();
    $booking = app(TicketingService::class)->reserve($this->user, $old, ['A', 'B', 'C', 'D'], (string) Str::uuid(), false);
    freezeLocal($this->city, '2026-10-23 12:00');
    AdmissionTicket::query()->whereKey($booking->tickets->first()->id)->update(['status' => AdmissionStatus::CheckedIn->value]);
    AdmissionTicket::query()->whereIn('id', $booking->tickets->skip(1)->pluck('id'))->update(['status' => AdmissionStatus::NoShow->value]);
    expect(app(TicketingService::class)->noShowCount($this->user))->toBe(0);
    unset($owner);
});

it('keeps saved-search matches found after the daily alert for the next day instead of losing them', function (): void {
    freezeLocal($this->city, '2026-10-23 09:00');
    $search = $this->user->savedSearches()->create(['city_id' => $this->city->id, 'name' => 'Musica', 'filters' => ['category' => 'musica-dal-vivo'], 'last_checked_at' => now()]);
    $matcher = app(SavedSearchMatcher::class);
    freezeLocal($this->city, '2026-10-23 10:00');
    $first = occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', event: ['title' => 'Prima']);
    expect($matcher->check($search->fresh()))->toBeTrue();
    freezeLocal($this->city, '2026-10-23 11:00');
    $second = occurrenceAtLocal($this->city, $this->category, '2026-10-31 21:00', event: ['title' => 'Seconda']);
    expect($matcher->check($search->fresh()))->toBeFalse();
    freezeLocal($this->city, '2026-10-24 10:00');
    expect($matcher->check($search->fresh()))->toBeTrue();
    $tomorrow = ScheduledNotification::query()->ofType('saved_search_match')->orderByDesc('id')->first();
    expect($tomorrow->context('occurrence_ids'))->toContain($second->id);
    unset($first);
});

it('moves collection items to the kept date when merging a duplicate', function (): void {
    freezeLocal($this->city, '2026-10-23 12:00');
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $keepDate = occurrenceAtLocal($this->city, $this->category, '2026-11-05 21:00', event: ['title' => 'Tenuta']);
    $dupDate = occurrenceAtLocal($this->city, $this->category, '2026-11-05 21:00', event: ['title' => 'Doppione'], venue: $keepDate->event->venue);
    $onlyDup = EventCollection::query()->create(['user_id' => $this->user->id, 'city_id' => $this->city->id, 'name' => 'Solo doppione']);
    $both = EventCollection::query()->create(['user_id' => $this->user->id, 'city_id' => $this->city->id, 'name' => 'Entrambe']);
    $onlyDup->items()->create(['occurrence_id' => $dupDate->id, 'note' => 'nota', 'position' => 1]);
    $both->items()->create(['occurrence_id' => $keepDate->id, 'position' => 1]);
    $both->items()->create(['occurrence_id' => $dupDate->id, 'position' => 2]);
    app(MergeEventsAction::class)->execute($keepDate->event, $dupDate->event, $admin);
    expect($onlyDup->items()->pluck('occurrence_id')->all())->toBe([$keepDate->id])
        ->and($onlyDup->items()->first()->note)->toBe('nota')
        ->and($both->items()->pluck('occurrence_id')->all())->toBe([$keepDate->id])
        ->and(DB::table('event_collection_items')->where('occurrence_id', $dupDate->id)->count())->toBe(0)
        ->and(Event::query()->find($dupDate->event_id))->toBeNull();
});
