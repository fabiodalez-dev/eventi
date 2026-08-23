<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\StatsPeriod;
use App\Filament\Venue\Pages\Statistics;
use App\Filament\Venue\Widgets\TodayScheduleWidget;
use App\Filament\Venue\Widgets\VenueOverviewWidget;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventViewDaily;
use App\Models\SavedEvent;
use App\Models\User;
use App\Queries\VenueDashboardQuery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * §10.1 (riepilogo) e §10.5 (statistiche).
 *
 * "Oggi" e "prossimi" non sono contati qui: arrivano da
 * `EventOccurrenceQuery`, e il test lo verifica sul caso che distingue le due
 * definizioni — una data di ieri non è "prossima", una di stanotte lo è.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;

    // Un'ora fissa: "oggi" dipende dal fuso della città, non da quando gira il test.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-10 18:00:00', 'Europe/Rome'));

    $this->actingAs($this->scenario->ownerA);

    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function occurrenceFor(Event $event, string $localDateTime): EventOccurrence
{
    $occurrence = new EventOccurrence([
        'event_id' => $event->getKey(),
        'starts_at' => CarbonImmutable::parse($localDateTime, 'Europe/Rome')->utc(),
        'is_all_day' => false,
        'is_exception' => false,
    ]);

    $occurrence->setRelation('event', $event);
    $occurrence->save();

    return $occurrence;
}

it('conta le date di oggi e quelle future del solo locale', function (): void {
    $mine = $this->scenario->publishedEventA;
    $mine->occurrences()->delete();

    occurrenceFor($mine, '2026-09-09 21:00');  // ieri
    occurrenceFor($mine, '2026-09-10 21:30');  // stasera
    occurrenceFor($mine, '2026-09-11 21:30');  // domani
    occurrenceFor($mine, '2026-09-20 21:30');  // fra dieci giorni

    // Il locale accanto, che non deve entrare in nessuno dei due numeri.
    occurrenceFor($this->scenario->publishedEventB, '2026-09-10 21:00');

    $dashboard = VenueDashboardQuery::for($this->venue);

    expect($dashboard->today())->toBe(1)
        ->and($dashboard->upcoming())->toBe(3);
});

it('non conta gli eventi che non sono ancora pubblicati', function (): void {
    // Il cartellone è ciò che il pubblico vede: una bozza non ci compare, e
    // il riepilogo del locale usa la stessa definizione del sito.
    $this->scenario->publishedEventA->occurrences()->delete();
    occurrenceFor($this->scenario->draftEventA, '2026-09-10 21:30');

    expect(VenueDashboardQuery::for($this->venue)->today())->toBe(0);
});

it('disegna il riquadro di stasera solo se c’è qualcosa', function (): void {
    $this->scenario->publishedEventA->occurrences()->delete();

    // §8.6: niente contenitori vuoti, nessuna scritta "nessun evento".
    expect(TodayScheduleWidget::canView())->toBeFalse();

    occurrenceFor($this->scenario->publishedEventA, '2026-09-10 22:00');

    expect(TodayScheduleWidget::canView())->toBeTrue();
});

it('mostra i tre numeri del riepilogo', function (): void {
    $this->scenario->publishedEventA->occurrences()->delete();
    occurrenceFor($this->scenario->publishedEventA, '2026-09-10 21:30');

    Livewire::test(VenueOverviewWidget::class)
        ->assertSee(__('manage.dashboard.today'))
        ->assertSee(__('manage.dashboard.upcoming'))
        ->assertSee(__('manage.dashboard.views'));
});

/*
 * -------------------------------------------------------------- statistiche
 */

function viewsOn(Event $event, string $date, int $views, int $directions = 0, int $tickets = 0): void
{
    EventViewDaily::query()->create([
        'event_id' => $event->getKey(),
        'date' => $date,
        'views' => $views,
        'unique_views' => $views,
        'direction_clicks' => $directions,
        'ticket_clicks' => $tickets,
    ]);
}

it('somma solo il periodo scelto e solo i propri eventi', function (): void {
    $mine = $this->scenario->publishedEventA;

    viewsOn($mine, '2026-09-10', 100, 10, 5);   // oggi
    viewsOn($mine, '2026-09-05', 50, 5, 2);     // sei giorni fa
    viewsOn($mine, '2026-08-20', 30, 3, 1);     // ventun giorni fa
    viewsOn($mine, '2026-06-01', 999, 99, 99);  // fuori da tutti i periodi
    viewsOn($this->scenario->publishedEventB, '2026-09-10', 1000, 100, 100);

    $dashboard = VenueDashboardQuery::for($this->venue);

    expect($dashboard->totals(StatsPeriod::Week)['views'])->toBe(150)
        ->and($dashboard->totals(StatsPeriod::Month)['views'])->toBe(180)
        ->and($dashboard->totals(StatsPeriod::Month)['direction_clicks'])->toBe(18)
        ->and($dashboard->totals(StatsPeriod::Month)['ticket_clicks'])->toBe(8);
});

it('conta i salvataggi senza esporre chi li ha fatti', function (): void {
    $occurrence = $this->scenario->occurrenceA;

    SavedEvent::query()->create([
        'user_id' => $this->scenario->plainUser->getKey(),
        'occurrence_id' => $occurrence->getKey(),
    ]);

    SavedEvent::query()->create([
        'user_id' => User::factory()->create()->getKey(),
        'occurrence_id' => $occurrence->getKey(),
    ]);

    $totals = VenueDashboardQuery::for($this->venue)->totals(StatsPeriod::Month);

    expect($totals['saves'])->toBe(2);
});

it('apre la pagina delle statistiche e cambia periodo', function (): void {
    viewsOn($this->scenario->publishedEventA, '2026-09-10', 42);

    Livewire::test(Statistics::class)
        ->assertSee(__('manage.statistics.views'))
        ->assertSee('42')
        ->call('setPeriod', StatsPeriod::Week->value)
        ->assertSet('period', StatsPeriod::Week->value)
        ->call('setPeriod', 'qualcosa-di-inventato')
        ->assertSet('period', StatsPeriod::default()->value);
});

it('non elenca gli eventi senza numeri', function (): void {
    // §8.6 di nuovo: l'elenco per evento esiste solo se ha qualcosa da dire.
    $page = Livewire::test(Statistics::class);

    expect($page->instance()->events())->toHaveCount(0);

    viewsOn($this->scenario->publishedEventA, '2026-09-10', 7);

    expect(Livewire::test(Statistics::class)->instance()->events())->toHaveCount(1);
});

it('non mostra a un locale i numeri di un altro', function (): void {
    viewsOn($this->scenario->publishedEventB, '2026-09-10', 500);

    Livewire::test(Statistics::class)->assertDontSee($this->scenario->publishedEventB->title);
});

it('esclude gli eventi archiviati dal conteggio delle date future', function (): void {
    $this->scenario->publishedEventA->occurrences()->delete();
    occurrenceFor($this->scenario->publishedEventA, '2026-09-15 21:00');

    expect(VenueDashboardQuery::for($this->venue)->upcoming())->toBe(1);

    $this->scenario->publishedEventA->update(['status' => EventStatus::Archived]);

    expect(VenueDashboardQuery::for($this->venue)->upcoming())->toBe(0);
});
