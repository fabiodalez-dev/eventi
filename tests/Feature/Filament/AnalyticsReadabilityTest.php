<?php

declare(strict_types=1);

use App\Enums\ContentMetric;
use App\Filament\Admin\Pages\EventAnalyticsDetail;
use App\Filament\Admin\Pages\EventShareAnalytics;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    freezeLocal($this->scenario->city, '2026-09-10 18:00');
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    $this->actingAs($this->scenario->admin);
    DB::table('event_views_daily')->insert(['event_id' => $this->scenario->publishedEventA->id, 'date' => '2026-09-10', ...array_fill_keys(array_column(ContentMetric::cases(), 'value'), 4)]);
});

it('shows the numbers with a plain sentence and readable dates', function (): void {
    $this->get(EventShareAnalytics::getUrl(['filters' => ['from' => '2026-09-01', 'until' => '2026-09-10']]))
        ->assertOk()
        ->assertSee('Statistiche in dettaglio')
        ->assertSee('data-stat-cards', false)
        ->assertSee(__('pannelli-statistiche.hints.views'))
        ->assertSee('mar 1 set')
        ->assertDontSee('01/09/2026');
});

it('opens an event page with the event row on top and lets me jump to another event', function (): void {
    $event = $this->scenario->publishedEventA;
    $this->get(EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $event->id]))
        ->assertOk()
        ->assertSee('data-analytics-event-header', false)
        ->assertSee('data-event-row', false)
        ->assertSee('Guarda un altro evento')
        ->assertDontSee('Evento #'.$event->id);

    $other = $this->scenario->publishedEventB;
    Livewire::test(EventAnalyticsDetail::class, ['subjectType' => 'event', 'subjectId' => $event->id])
        ->set('otherEvent', $other->id)
        ->assertRedirectContains('/statistiche-condivisioni/event/'.$other->id);
});

it('refuses to jump to an event that does not exist', function (): void {
    $event = $this->scenario->publishedEventA;
    $this->get(EventAnalyticsDetail::getUrl(['subjectType' => 'event', 'subjectId' => $event->id]))->assertOk();
    Livewire::test(EventAnalyticsDetail::class, ['subjectType' => 'event', 'subjectId' => $event->id])
        ->call('goToEvent', 999999)
        ->assertStatus(404);
});
