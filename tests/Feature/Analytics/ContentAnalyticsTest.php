<?php

declare(strict_types=1);

use App\DTOs\ConsentState;
use App\Enums\ContentMetric;
use App\Enums\StatsPeriod;
use App\Filament\Organizer\Pages\Statistics as OrganizerStatistics;
use App\Filament\Venue\Pages\Statistics;
use App\Models\EventViewDaily;
use App\Models\Organizer;
use App\Models\User;
use App\Services\Analytics\ManagementAnalytics;
use App\Services\Analytics\RecordContentMetric;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\VenueIsolationScenario;

beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    freezeLocal($this->scenario->city, '2026-09-10 18:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('records each metric atomically on the local day only after statistics consent', function (): void {
    $event = $this->scenario->publishedEventA;
    $url = URL::signedRoute('content.metrics', ['type' => 'event', 'id' => $event->id], absolute: false);
    $this->postJson($url, ['metric' => 'views'])->assertNoContent();
    expect(EventViewDaily::count())->toBe(0);
    $state = new ConsentState('analytics-test', config('consent.version'), ['necessary' => true, 'statistics' => true]);
    $this->withCredentials()->withCookie(config('consent.cookie'), $state->encode());
    foreach (ContentMetric::cases() as $metric) {
        $this->postJson($url, ['metric' => $metric->value])->assertNoContent();
        $this->postJson($url, ['metric' => $metric->value])->assertNoContent();
    }
    $row = EventViewDaily::sole();
    expect($row->date->toDateString())->toBe('2026-09-10');
    foreach (ContentMetric::cases() as $metric) {
        expect((int) $row->getAttribute($metric->value))->toBe(2);
    }
    $this->postJson($url, ['metric' => 'invalid_column'])->assertUnprocessable();
    $this->postJson('/misure/event/'.$event->id, ['metric' => 'views'])->assertForbidden();
});

it('rejects unpublished targets and keeps venue and organizer profiles separate', function (): void {
    $state = new ConsentState('analytics-test', config('consent.version'), ['statistics' => true]);
    $this->withCredentials()->withCookie(config('consent.cookie'), $state->encode());
    $url = URL::signedRoute('content.metrics', ['type' => 'event', 'id' => $this->scenario->draftEventA->id], absolute: false);
    $this->postJson($url, ['metric' => 'views'])->assertNotFound();
    $organizer = Organizer::create(['name' => 'Organizzazione', 'city_id' => $this->scenario->city->id, 'owner_id' => $this->scenario->ownerA->id, 'is_active' => true]);
    foreach (['venue' => $this->scenario->venueA, 'organizer' => $organizer] as $type => $profile) {
        $url = URL::signedRoute('content.metrics', ['type' => $type, 'id' => $profile->id], absolute: false);
        $this->postJson($url, ['metric' => 'views'])->assertNoContent();
        $this->assertDatabaseHas('profile_views_daily', ['profile_type' => $type, 'profile_id' => $profile->id, 'views' => 1]);
    }
});

it('exposes profile and event totals including click-only events and excludes other venues and dates', function (): void {
    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
    $record = app(RecordContentMetric::class);
    $record->record('event', $this->scenario->publishedEventA->id, ContentMetric::Tickets);
    $record->record('venue', $this->scenario->venueA->id, ContentMetric::Views);
    $record->record('event', $this->scenario->publishedEventB->id, ContentMetric::Views);
    EventViewDaily::create(['event_id' => $this->scenario->publishedEventA->id, 'date' => '2026-06-01', 'views' => 900]);
    $report = app(ManagementAnalytics::class)->report(StatsPeriod::Week);
    expect($report['totals']['views'])->toBe(0)->and($report['totals']['ticket_clicks'])->toBe(1)
        ->and($report['profile']['views'])->toBe(1)->and($report['series'])->toHaveCount(7);
    Livewire::test(Statistics::class)->assertSee($this->scenario->publishedEventA->title)->assertDontSee($this->scenario->publishedEventB->title);
    Filament::setTenant($this->scenario->venueB);
    expect(fn () => app(ManagementAnalytics::class)->report(StatsPeriod::Week))->toThrow(HttpException::class);
});

it('limits organizer analytics to their own events even at venues they do not manage', function (): void {
    $owner = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo', 'city_id' => $this->scenario->city->id, 'owner_id' => $owner->id, 'is_active' => true]);
    $this->scenario->publishedEventB->update(['organizer_id' => $organizer->id]);
    app(RecordContentMetric::class)->record('event', $this->scenario->publishedEventB->id, ContentMetric::Views);
    app(RecordContentMetric::class)->record('event', $this->scenario->publishedEventA->id, ContentMetric::Views);
    $this->actingAs($owner);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);
    Livewire::test(OrganizerStatistics::class)->assertSee($this->scenario->publishedEventB->title)->assertDontSee($this->scenario->publishedEventA->title)
        ->call('setPeriod', 'week')->assertSet('period', 'week')->call('setPeriod', 'invalid')->assertSet('period', 'month');
    expect(app(ManagementAnalytics::class)->report(StatsPeriod::Month)['totals']['views'])->toBe(1);
    $this->actingAs($this->scenario->ownerA);
    expect(fn () => app(ManagementAnalytics::class)->report(StatsPeriod::Week))->toThrow(HttpException::class);
});

it('embeds a signed counter on public detail pages but not editorial previews', function (): void {
    $this->get(route('events.show', $this->scenario->publishedEventA))->assertOk()->assertSee('data-content-analytics', false);
    $this->get(route('venues.show', $this->scenario->venueA))->assertOk()->assertSee('data-content-analytics', false);
    $this->actingAs($this->scenario->ownerA)->get(route('events.preview', $this->scenario->draftEventA))->assertOk()->assertDontSee('data-content-analytics', false);
});
