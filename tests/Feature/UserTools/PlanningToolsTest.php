<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\SavedEvent;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Services\Notifications\DigestPlanner;
use App\Services\Notifications\MessageFactory;
use App\Support\OpeningHours;
use Carbon\CarbonImmutable;

afterEach(fn () => Carbon\Carbon::setTestNow());

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->user = User::factory()->create(['email_verified_at' => now(), 'timezone' => 'Europe/Rome']);
});

it('reports overlapping saved and booked dates when saving, on the site and the API', function (): void {
    freezeLocal($this->city, '2026-10-23 12:00');
    $a = occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', '2026-10-30 23:00', event: ['title' => 'Concerto A']);
    $b = occurrenceAtLocal($this->city, $this->category, '2026-10-30 22:00', '2026-10-31 00:30', event: ['title' => 'Festa B']);
    $c = occurrenceAtLocal($this->city, $this->category, '2026-10-31 21:00', null, event: ['title' => 'Altro giorno']);
    SavedEvent::create(['user_id' => $this->user->id, 'occurrence_id' => $a->id]);
    $web = $this->actingAs($this->user)->postJson(route('account.saved.store'), ['occurrence_id' => $b->id])->assertOk();
    expect($web->json('conflicts.0.occurrence_id'))->toBe($a->id)
        ->and($web->json('conflicts.0.kind'))->toBe('saved')
        ->and($web->json('message'))->toContain('Concerto A')->toContain('21:00');
    $api = $this->actingAs($this->user)->postJson('/api/v1/me/saved', ['occurrence_id' => $c->id])->assertCreated();
    expect($api->json('data.conflicts'))->toBe([]);
    $this->actingAs($this->user)->get(route('account.saved'))->assertOk()->assertSee(__('account.saved.overlap', ['title' => 'Festa B']), false);
});

it('plans the Monday digest of saved dates and lists the week with its changes', function (): void {
    freezeLocal($this->city, '2026-10-25 20:00');
    $date = occurrenceAtLocal($this->city, $this->category, '2026-10-28 21:00', event: ['title' => 'Mercoledì jazz']);
    $cancelled = occurrenceAtLocal($this->city, $this->category, '2026-10-29 21:00', event: ['title' => 'Giovedì annullato']);
    $cancelled->update(['status' => 'cancelled']);
    SavedEvent::create(['user_id' => $this->user->id, 'occurrence_id' => $date->id]);
    SavedEvent::create(['user_id' => $this->user->id, 'occurrence_id' => $cancelled->id]);
    $planned = app(DigestPlanner::class)->plan();
    expect($planned[NotificationType::SavedWeekly->value])->toBe(1);
    $row = ScheduledNotification::query()->ofType('saved_weekly')->sole();
    expect(CarbonImmutable::parse($row->send_at)->setTimezone('Europe/Rome')->format('D H:i'))->toBe('Mon 09:00');
    $message = app(MessageFactory::class)->build($row, $this->user);
    expect($message->items)->toHaveCount(2)
        ->and($message->items[1]['meta'])->toContain(__('notifications.saved_weekly.cancelled'))
        ->and($message->url)->toBe(route('account.saved'));
    $this->user->update(['notification_preferences' => ['reminders' => false]]);
    ScheduledNotification::query()->delete();
    expect(app(DigestPlanner::class)->plan()[NotificationType::SavedWeekly->value])->toBe(0);
});

it('computes open now in the city timezone, overnight ranges included, and filters venues by it', function (): void {
    $hours = ['fri' => [['open' => '21:00', 'close' => '02:00']], 'sat' => [['open' => '10:00', 'close' => '13:00']]];
    expect(OpeningHours::state($hours, CarbonImmutable::parse('2026-10-24 09:30', 'UTC'), 'Europe/Rome'))->toBe(['open' => true, 'until' => '13:00'])
        ->and(OpeningHours::state($hours, CarbonImmutable::parse('2026-10-23 23:30', 'UTC'), 'Europe/Rome'))->toBe(['open' => true, 'until' => '02:00'])
        ->and(OpeningHours::state($hours, CarbonImmutable::parse('2026-10-24 12:00', 'UTC'), 'Europe/Rome'))->toBe(['open' => false, 'until' => null])
        ->and(OpeningHours::state(null, now(), 'Europe/Rome'))->toBe(['open' => null, 'until' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-10-24 09:30', 'UTC'));
    $open = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Aperto Sabato', 'opening_hours' => $hours]);
    $closed = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Chiuso Sabato', 'opening_hours' => ['mon' => [['open' => '10:00', 'close' => '12:00']]]]);
    $this->getJson('/api/v1/venues/'.$open->slug)->assertOk()->assertJsonPath('data.open_now', true)->assertJsonPath('data.open_until', '13:00');
    $list = $this->getJson('/api/v1/venues?open_now=1')->assertOk();
    expect(collect($list->json('data'))->pluck('slug')->all())->toContain($open->slug)->not->toContain($closed->slug);
    $page = $this->get(route('venues.index', ['aperto' => 1]))->assertOk()->assertSee('Aperto Sabato')->assertDontSee('Chiuso Sabato')->assertSee('data-open-now', false);
    unset($page);
});
