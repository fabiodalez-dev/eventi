<?php

declare(strict_types=1);

use App\Actions\UpdateOccurrencesAction;
use App\Enums\OccurrenceScope;
use App\Enums\OccurrenceStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * §9.2 — «modifica singola occorrenza **vs** modifica intera serie».
 *
 * Le due scelte devono produrre esiti diversi e verificabili: la prima tocca
 * una riga sola, la seconda tutte le date future della stessa ricorrenza,
 * lasciando stare quelle già modificate a mano (D21) e quelle passate.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC'));

    $this->city = City::factory()->padova()->create();
    $this->category = Category::factory()->create(['default_duration_minutes' => 180]);
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $this->event = Event::factory()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
        'venue_id' => $venue->getKey(),
    ]);

    $this->recurrence = EventRecurrence::factory()->create([
        'event_id' => $this->event->getKey(),
        'rrule' => 'FREQ=WEEKLY;BYDAY=TH;COUNT=5',
    ]);

    // Cinque giovedì consecutivi, tutti futuri.
    $this->dates = collect(range(0, 4))->map(fn (int $week): EventOccurrence => EventOccurrence::factory()->create([
        'event_id' => $this->event->getKey(),
        'recurrence_id' => $this->recurrence->getKey(),
        'starts_at' => CarbonImmutable::parse('2026-09-03 19:00:00', 'UTC')->addWeeks($week),
        'ends_at' => null,
        'doors_at' => null,
        'status' => OccurrenceStatus::Scheduled,
        'is_exception' => false,
    ]));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('annulla una sola data e lascia intatte le altre della serie', function (): void {
    $changed = app(UpdateOccurrencesAction::class)->changeStatus(
        $this->dates[2],
        OccurrenceStatus::Cancelled,
        'Chiusura straordinaria',
        OccurrenceScope::Single,
    );

    expect($changed)->toBe(1)
        ->and($this->dates[2]->refresh()->status)->toBe(OccurrenceStatus::Cancelled)
        // L'observer marca la data come eccezione: la serie non la toccherà più (D21).
        ->and($this->dates[2]->is_exception)->toBeTrue();

    foreach ([0, 1, 3, 4] as $index) {
        expect($this->dates[$index]->refresh()->status)->toBe(OccurrenceStatus::Scheduled)
            ->and($this->dates[$index]->is_exception)->toBeFalse();
    }
});

it('annulla la serie dalla data scelta in avanti, senza toccare il passato', function (): void {
    $changed = app(UpdateOccurrencesAction::class)->changeStatus(
        $this->dates[2],
        OccurrenceStatus::Cancelled,
        null,
        OccurrenceScope::Series,
    );

    expect($changed)->toBe(3);

    foreach ([0, 1] as $index) {
        expect($this->dates[$index]->refresh()->status)->toBe(OccurrenceStatus::Scheduled);
    }

    foreach ([2, 3, 4] as $index) {
        expect($this->dates[$index]->refresh()->status)->toBe(OccurrenceStatus::Cancelled);
    }
});

it('non trascina nella serie una data già modificata a mano', function (): void {
    // La quarta data viene spostata a mano: da quel momento è un'eccezione.
    app(UpdateOccurrencesAction::class)->shift($this->dates[3], 60, OccurrenceScope::Single);

    expect($this->dates[3]->refresh()->is_exception)->toBeTrue();

    $movedAt = $this->dates[3]->starts_at->copy();

    $changed = app(UpdateOccurrencesAction::class)->shift($this->dates[1], 30, OccurrenceScope::Series);

    // Prima, terza e quinta si spostano; la quarta no perché è un'eccezione.
    expect($changed)->toBe(3)
        ->and($this->dates[3]->refresh()->starts_at->equalTo($movedAt))->toBeTrue();
});

it('tratta una data senza ricorrenza come sempre singola', function (): void {
    $standalone = EventOccurrence::factory()->create([
        'event_id' => $this->event->getKey(),
        'recurrence_id' => null,
        'starts_at' => CarbonImmutable::parse('2026-10-10 21:00:00', 'UTC'),
        'ends_at' => null,
    ]);

    $changed = app(UpdateOccurrencesAction::class)->changeStatus(
        $standalone,
        OccurrenceStatus::SoldOut,
        null,
        OccurrenceScope::Series,
    );

    expect($changed)->toBe(1)
        ->and($this->dates[0]->refresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('ricalcola la giornata evento quando la data si sposta', function (): void {
    // Categoria di vita notturna: dopo la mezzanotte la data appartiene alla
    // sera precedente (§8.2). Spostare l'orario deve rifare quel conto.
    $this->category->update(['is_nightlife' => true]);

    $late = EventOccurrence::factory()->create([
        'event_id' => $this->event->getKey(),
        'recurrence_id' => null,
        'starts_at' => CarbonImmutable::parse('2026-09-05 21:00:00', 'Europe/Rome')->utc(),
        'ends_at' => null,
    ]);

    expect($late->business_date->toDateString())->toBe('2026-09-05');

    app(UpdateOccurrencesAction::class)->shift($late, 5 * 60, OccurrenceScope::Single);

    // Ora comincia alle 2:00 del 6 settembre: resta la serata del 5.
    expect($late->refresh()->starts_at->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-09-06 02:00')
        ->and($late->business_date->toDateString())->toBe('2026-09-05');
});

it('offre la scelta fra data e serie dal pannello, e la applica', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $this->actingAs($admin);

    Livewire::test(OccurrencesRelationManager::class, [
        'ownerRecord' => $this->event,
        'pageClass' => EditEvent::class,
    ])
        ->callTableAction('cancel_occurrence', $this->dates[3], [
            'status_note' => 'Rinviato',
            'scope' => OccurrenceScope::Series->value,
        ])
        ->assertHasNoTableActionErrors();

    expect($this->dates[2]->refresh()->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($this->dates[3]->refresh()->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($this->dates[4]->refresh()->status)->toBe(OccurrenceStatus::Cancelled);
});
