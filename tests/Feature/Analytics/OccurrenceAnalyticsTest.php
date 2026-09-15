<?php

declare(strict_types=1);

use App\DTOs\ConsentState;
use App\Enums\ContentMetric;
use App\Filament\Organizer\Resources\Events\DatesRelationManager;
use App\Filament\Organizer\Resources\Events\Pages\EditEvent;
use App\Models\Organizer;
use App\Services\Analytics\OccurrenceAnalytics;
use App\Services\Analytics\RecordContentMetric;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\VenueIsolationScenario;

it('attributes metrics to the selected date once while preserving event totals and isolating replicas', function (): void {
    $scenario = VenueIsolationScenario::make();
    $event = $scenario->publishedEventA;
    $first = $event->occurrences()->firstOrFail();
    $second = $event->occurrences()->create(['starts_at' => $first->starts_at->copy()->addDay()]);
    $recorder = app(RecordContentMetric::class);
    $recorder->record('event', $event->id, ContentMetric::Views, $first->id);
    $recorder->record('event', $event->id, ContentMetric::Views, $first->id);
    $recorder->record('event', $event->id, ContentMetric::Tickets, $second->id);
    $recorder->record('event', $event->id, ContentMetric::Views);
    expect((int) DB::table('event_views_daily')->where('event_id', $event->id)->sum('views'))->toBe(3);
    $this->actingAs($scenario->ownerA);
    expect(app(OccurrenceAnalytics::class)->report($first)['totals']['views'])->toBe(2);
    expect(app(OccurrenceAnalytics::class)->report($second)['totals']['views'])->toBe(0);
    expect(app(OccurrenceAnalytics::class)->report($second)['totals']['ticket_clicks'])->toBe(1);
    expect(fn () => $recorder->record('event', $scenario->publishedEventB->id, ContentMetric::Views, $first->id))->toThrow(HttpException::class);
    $this->actingAs($scenario->ownerB);
    expect(fn () => app(OccurrenceAnalytics::class)->report($first))->toThrow(AuthorizationException::class);
});

it('renders per-date totals and the analytics action in the organizer event', function (): void {
    $scenario = VenueIsolationScenario::make();
    $organizer = Organizer::create(['name' => 'Portici', 'owner_id' => $scenario->ownerA->id, 'city_id' => $scenario->city->id, 'is_active' => true]);
    $event = $scenario->publishedEventA;
    $event->update(['organizer_id' => $organizer->id]);
    $date = $event->occurrences()->firstOrFail();
    app(RecordContentMetric::class)->record('event', $event->id, ContentMetric::Views, $date->id);
    $this->actingAs($scenario->ownerA);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);
    Livewire::test(DatesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertCanSeeTableRecords([$date])->assertTableColumnStateSet('analytics_views', 1, $date)
        ->mountTableAction('analytics', $date)->assertSet('mountedActions.0.name', 'analytics');
    expect(view('filament.occurrence-analytics', ['report' => app(OccurrenceAnalytics::class)->report($date)])->render())->toContain('data-occurrence-analytics');
});

it('signs the selected occurrence and rejects a tampered date at the tracking endpoint', function (): void {
    $scenario = VenueIsolationScenario::make();
    $date = $scenario->publishedEventA->occurrences()->firstOrFail();
    $url = URL::signedRoute('content.metrics', ['type' => 'event', 'id' => $date->event_id, 'occurrence' => $date->id], absolute: false);
    $state = new ConsentState('occurrence-test', config('consent.version'), ['statistics' => true]);
    $this->withCredentials()->withCookie(config('consent.cookie'), $state->encode());
    $this->postJson($url, ['metric' => 'views'])->assertNoContent();
    $this->assertDatabaseHas('occurrence_views_daily', ['occurrence_id' => $date->id, 'views' => 1]);
    $this->postJson(str_replace('occurrence='.$date->id, 'occurrence=999999', $url), ['metric' => 'views'])->assertForbidden();
    $this->get(route('events.occurrence', ['slug' => $date->event->slug, 'occurrence' => $date->url_number]))
        ->assertOk()->assertSee('occurrence='.$date->id, false);
});
