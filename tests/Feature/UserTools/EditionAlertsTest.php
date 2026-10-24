<?php

declare(strict_types=1);

use App\Actions\MergeEventsAction;
use App\Enums\EventStatus;
use App\Models\EditionAlert;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Services\Events\EditionAlerts;
use App\Services\Notifications\MessageFactory;
use Carbon\CarbonImmutable;

afterEach(fn () => Carbon\Carbon::setTestNow());

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->organizer = Organizer::create(['name' => 'Pro Loco', 'city_id' => $this->city->id, 'is_active' => true]);
    freezeLocal($this->city, '2026-10-24 12:00');
    $this->past = occurrenceAtLocal($this->city, $this->category, '2026-05-10 18:00', null, event: ['title' => 'Sagra del Bisi 2026 – 12ª edizione', 'organizer_id' => $this->organizer->id]);
});

it('normalizes titles so that yearly editions match and different events do not', function (): void {
    expect(EditionAlerts::titleKey('Sagra del Bisi 2026 – 12ª edizione'))->toBe('sagra del bisi')
        ->and(EditionAlerts::titleKey('SAGRA DEL BISI 2027'))->toBe('sagra del bisi')
        ->and(EditionAlerts::titleKey('Festival Jazz – XIII edizione'))->toBe('festival jazz')
        ->and(EditionAlerts::titleKey('Sagra della Polenta 2027'))->not->toBe('sagra del bisi');
});

it('offers the alert only on events without upcoming dates, on the site and the API', function (): void {
    $running = occurrenceAtLocal($this->city, $this->category, '2026-11-10 18:00', null, event: ['title' => 'Rassegna in corso']);

    $this->actingAs($this->user)->get(route('events.show', $this->past->event))->assertOk()->assertSee(__('events.edition_alert.subscribe'));
    $this->actingAs($this->user)->postJson('/api/v1/me/edition-alerts/'.$running->event_id)->assertUnprocessable();
    $this->actingAs($this->user)->postJson('/api/v1/me/edition-alerts/'.$this->past->event_id)->assertCreated();
    expect($this->actingAs($this->user)->getJson('/api/v1/me/edition-alerts')->json('data'))->toBe([$this->past->event_id]);
    $this->actingAs($this->user)->get(route('events.show', $this->past->event))->assertSee(__('events.edition_alert.active'));
    $this->actingAs($this->user)->deleteJson('/api/v1/me/edition-alerts/'.$this->past->event_id)->assertNoContent();
    expect(EditionAlert::count())->toBe(0);
});

it('announces a new event of the same organizer with the same title, once', function (): void {
    $this->actingAs($this->user)->post(route('account.edition-alerts.store', $this->past->event_id))->assertRedirect();
    occurrenceAtLocal($this->city, $this->category, '2027-05-09 18:00', null, event: ['title' => 'Sagra della Polenta 2027', 'organizer_id' => $this->organizer->id]);
    occurrenceAtLocal($this->city, $this->category, '2027-05-09 18:00', null, event: ['title' => 'Sagra del Bisi 2027', 'status' => EventStatus::Draft, 'organizer_id' => $this->organizer->id]);
    $this->artisan('editions:notify')->assertSuccessful();
    expect(ScheduledNotification::query()->ofType('next_edition')->count())->toBe(0)->and(EditionAlert::count())->toBe(1);

    $this->travelTo(now()->addDay());
    $next = occurrenceAtLocal($this->city, $this->category, '2027-05-16 18:00', null, event: ['title' => 'SAGRA DEL BISI 2027 – 13ª edizione', 'organizer_id' => $this->organizer->id]);
    $this->artisan('editions:notify')->assertSuccessful();
    $row = ScheduledNotification::query()->ofType('next_edition')->sole();
    expect($row->notifiable_id)->toBe($next->id)->and(EditionAlert::count())->toBe(0);
    expect(app(MessageFactory::class)->build($row, $this->user)->subject)->toContain('SAGRA DEL BISI 2027');
    $this->artisan('editions:notify')->assertSuccessful();
    expect(ScheduledNotification::query()->ofType('next_edition')->count())->toBe(1);
});

it('announces a new date of the same event, and ignores organizers that do not match', function (): void {
    $this->actingAs($this->user)->postJson('/api/v1/me/edition-alerts/'.$this->past->event_id)->assertCreated();
    $other = Organizer::create(['name' => 'Altri', 'city_id' => $this->city->id, 'is_active' => true]);
    occurrenceAtLocal($this->city, $this->category, '2027-05-09 18:00', null, event: ['title' => 'Sagra del Bisi 2027', 'organizer_id' => $other->id]);
    $this->artisan('editions:notify')->assertSuccessful();
    expect(ScheduledNotification::query()->ofType('next_edition')->count())->toBe(0);

    $this->travelTo(now()->addHour());
    $again = EventOccurrence::factory()->create(['event_id' => $this->past->event_id, 'starts_at' => CarbonImmutable::parse('2027-05-23 16:00', 'UTC'), 'doors_at' => null]);
    $this->artisan('editions:notify')->assertSuccessful();
    expect(ScheduledNotification::query()->ofType('next_edition')->sole()->notifiable_id)->toBe($again->id);
});

it('moves the alert to the surviving event when duplicates are merged', function (): void {
    $this->actingAs($this->user)->postJson('/api/v1/me/edition-alerts/'.$this->past->event_id)->assertCreated();
    $keep = Event::factory()->create(['city_id' => $this->city->id, 'category_id' => $this->category->id, 'status' => EventStatus::Published, 'published_at' => now()]);
    app(MergeEventsAction::class)->execute($keep, $this->past->event, User::factory()->create());
    expect(EditionAlert::sole()->event_id)->toBe($keep->id);
});
