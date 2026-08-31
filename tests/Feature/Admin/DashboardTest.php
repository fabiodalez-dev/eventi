<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\ImportRunStatus;
use App\Enums\PriceType;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Filament\Admin\Widgets\ContentQualityWidget;
use App\Filament\Admin\Widgets\EditorialQueueWidget;
use App\Filament\Admin\Widgets\PublishingWidget;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\ImportSource;
use App\Models\Report;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EditorialDashboardQuery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * §9.1 — i riquadri della dashboard. Ogni numero deve corrispondere a righe
 * vere, e ogni riquadro deve portare alla lista filtrata sulla stessa
 * condizione: numero e lista nascono dallo stesso metodo di
 * `EditorialDashboardQuery`.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC'));

    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
    $this->actingAs($this->admin);

    $this->city = City::factory()->padova()->create();
    $this->category = Category::factory()->create(['default_duration_minutes' => 180]);
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function makeEvent(array $attributes = []): Event
{
    return Event::factory()->create([
        'city_id' => test()->city->getKey(),
        'category_id' => test()->category->getKey(),
        'venue_id' => test()->venue->getKey(),
        'description' => 'Una descrizione sufficiente.',
        'price_type' => PriceType::Free,
        'poster' => 'locandina.jpg',
        ...$attributes,
    ]);
}

it('conta le code di lavoro della redazione', function (): void {
    makeEvent(['status' => EventStatus::Pending]);
    makeEvent(['status' => EventStatus::Pending]);
    makeEvent(['status' => EventStatus::Cancelled]);

    Venue::factory()->create(['city_id' => $this->city->getKey(), 'status' => VenueStatus::Pending]);

    Report::factory()->create(['status' => ReportStatus::Pending, 'reason' => ReportReason::WrongInfo]);
    Report::factory()->create(['status' => ReportStatus::Reviewing, 'reason' => ReportReason::Spam]);
    Report::factory()->create(['status' => ReportStatus::Resolved, 'reason' => ReportReason::Spam]);

    $dashboard = EditorialDashboardQuery::for($this->city);

    expect($dashboard->pendingEvents()->count())->toBe(2)
        ->and($dashboard->pendingVenues()->count())->toBe(1)
        ->and($dashboard->cancelledEvents()->count())->toBe(1)
        ->and($dashboard->openReports()->count())->toBe(2);
});

it('separa ciò che è stato pubblicato oggi da ciò che è in cartellone oggi', function (): void {
    // Pubblicato ieri, ma in programma oggi.
    $event = makeEvent([
        'status' => EventStatus::Published,
        'published_at' => CarbonImmutable::parse('2026-09-09 08:00:00', 'UTC'),
    ]);

    EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => CarbonImmutable::parse('2026-09-10 21:00:00', 'Europe/Rome')->utc(),
        'ends_at' => null,
    ]);

    // Pubblicato stamattina, ma in cartellone il mese prossimo.
    $later = makeEvent([
        'status' => EventStatus::Published,
        'published_at' => CarbonImmutable::parse('2026-09-10 07:00:00', 'UTC'),
    ]);

    EventOccurrence::factory()->create([
        'event_id' => $later->getKey(),
        'starts_at' => CarbonImmutable::parse('2026-10-20 21:00:00', 'Europe/Rome')->utc(),
        'ends_at' => null,
    ]);

    $dashboard = EditorialDashboardQuery::for($this->city);

    expect($dashboard->publishedToday()->count())->toBe(1)
        ->and($dashboard->publishedThisWeek()->count())->toBe(2)
        // "Oggi" del cartellone lo definisce EventOccurrenceQuery, non questa classe.
        ->and($dashboard->scheduledToday())->toBe(1);
});

it('trova le schede incomplete e quelle senza locandina', function (): void {
    makeEvent(['status' => EventStatus::Published, 'poster' => null]);
    makeEvent(['status' => EventStatus::Published, 'description' => null]);
    makeEvent(['status' => EventStatus::Published, 'price_type' => PriceType::Unknown]);
    makeEvent(['status' => EventStatus::Published, 'venue_id' => null, 'custom_location' => null]);
    makeEvent(['status' => EventStatus::Published]);

    $dashboard = EditorialDashboardQuery::for($this->city);

    expect($dashboard->eventsWithoutPoster()->count())->toBe(1)
        ->and($dashboard->incompleteEvents()->count())->toBe(3);
});

it('riconosce come duplicati due schede quasi identiche nella stessa serata', function (): void {
    $first = makeEvent(['status' => EventStatus::Published, 'title' => 'Concerto di musica popolare']);
    $second = makeEvent(['status' => EventStatus::Published, 'title' => 'Concerto di musica popolare!']);
    $other = makeEvent(['status' => EventStatus::Published, 'title' => 'Reading di poesia contemporanea']);

    $when = CarbonImmutable::parse('2026-09-25 21:00:00', 'Europe/Rome')->utc();

    foreach ([$first, $second, $other] as $event) {
        EventOccurrence::factory()->create([
            'event_id' => $event->getKey(),
            'starts_at' => $when,
            'ends_at' => null,
        ]);
    }

    $dashboard = EditorialDashboardQuery::for($this->city);
    $ids = $dashboard->possibleDuplicateIds();

    sort($ids);

    expect($ids)->toBe([$first->getKey(), $second->getKey()])
        ->and($dashboard->possibleDuplicates()->count())->toBe(2);
});

it('considera inattivo un locale senza date in cartellone da sessanta giorni', function (): void {
    $silent = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
    $active = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $old = makeEvent(['venue_id' => $silent->getKey(), 'status' => EventStatus::Published]);
    EventOccurrence::factory()->create([
        'event_id' => $old->getKey(),
        'starts_at' => CarbonImmutable::parse('2026-05-01 21:00:00', 'UTC'),
        'ends_at' => null,
    ]);

    $recent = makeEvent(['venue_id' => $active->getKey(), 'status' => EventStatus::Published]);
    EventOccurrence::factory()->create([
        'event_id' => $recent->getKey(),
        'starts_at' => CarbonImmutable::parse('2026-09-20 21:00:00', 'UTC'),
        'ends_at' => null,
    ]);

    $inactive = EditorialDashboardQuery::for($this->city)->inactiveVenues()->pluck('id')->all();

    expect($inactive)->toContain($silent->getKey())
        ->and($inactive)->not->toContain($active->getKey());
});

it('conta le sorgenti la cui ultima esecuzione e fallita', function (): void {
    // Attiva e fallita: va contata.
    ImportSource::factory()->create([
        'city_id' => $this->city->getKey(),
        'is_active' => true,
        'last_status' => ImportRunStatus::Failed->value,
        'last_error' => 'Connessione rifiutata',
    ]);

    // Attiva e riuscita: non va contata.
    ImportSource::factory()->create([
        'city_id' => $this->city->getKey(),
        'is_active' => true,
        'last_status' => ImportRunStatus::Success->value,
        'last_error' => null,
    ]);

    // Spenta: non va contata, anche se l'ultima esecuzione era fallita.
    // Nessuno deve inseguire una sorgente che e stata deliberatamente sospesa.
    ImportSource::factory()->create([
        'city_id' => $this->city->getKey(),
        'is_active' => false,
        'last_status' => ImportRunStatus::Failed->value,
        'last_error' => 'Vecchio errore su una sorgente spenta',
    ]);

    expect(EditorialDashboardQuery::for($this->city)->failedImports()->count())->toBe(1);
});

/*
 * Il conteggio legge `last_status`, non `last_error`.
 *
 * Sono due cose diverse: lo stato dice SE l'esecuzione e fallita, il messaggio
 * dice PERCHE. Un driver che fallisce senza produrre un messaggio leggibile —
 * un timeout, un processo ucciso — lascia `last_error` a null: contando i
 * messaggi, quella sorgente resterebbe rotta e invisibile proprio nel riquadro
 * che esiste per accorgersene.
 */
it('conta una sorgente fallita anche quando non ha lasciato un messaggio', function (): void {
    ImportSource::factory()->create([
        'city_id' => $this->city->getKey(),
        'is_active' => true,
        'last_status' => ImportRunStatus::Failed->value,
        'last_error' => null,
    ]);

    expect(EditorialDashboardQuery::for($this->city)->failedImports()->count())->toBe(1);
});

it('disegna i tre riquadri e dà a ognuno un collegamento alla lista filtrata', function (): void {
    makeEvent(['status' => EventStatus::Pending]);

    Livewire::test(EditorialQueueWidget::class)
        ->assertOk()
        ->assertSee(__('admin.dashboard.pending_events'))
        ->assertSee(__('admin.dashboard.open_reports'));

    Livewire::test(PublishingWidget::class)
        ->assertOk()
        ->assertSee(__('admin.dashboard.published_today'))
        ->assertSee(__('admin.dashboard.scheduled_today'));

    Livewire::test(ContentQualityWidget::class)
        ->assertOk()
        ->assertSee(__('admin.dashboard.missing_poster'))
        ->assertSee(__('admin.dashboard.possible_duplicates'));

    $stats = (fn () => $this->getStats())->call(new EditorialQueueWidget);

    expect($stats)->toHaveCount(4);

    foreach ($stats as $stat) {
        expect($stat->getUrl())->toStartWith('http');
    }
});

it('non disegna alcun riquadro finché non esiste una città', function (): void {
    // `venues.city_id` è RESTRICT di proposito: una città con contenuti non si
    // cancella. Per riprodurre l'impianto vuoto va svuotata prima la provincia.
    Venue::query()->forceDelete();
    City::query()->delete();

    expect(EditorialQueueWidget::canView())->toBeFalse()
        ->and(PublishingWidget::canView())->toBeFalse()
        ->and(ContentQualityWidget::canView())->toBeFalse();
});
