<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Enums\RecurrenceFrequency;
use App\Enums\Weekday;
use App\Filament\Venue\Pages\VenueProfile;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Event;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge le chiusure straordinarie del locale (D63) lungo il lavoro sugli
 * eventi: il gestore le scrive nel profilo, il pubblico le legge sulla
 * pagina del locale, e chi aggiunge o sposta una data in quei giorni viene
 * avvisato senza essere fermato — una serata privata a locale chiuso resta
 * possibile. Un evento con un indirizzo suo non guarda le chiusure del locale.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    $this->venue->update(['auto_publish' => true]);
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function geChiudi(object $test, array $chiusure): void
{
    geEntra($test->owner, $test->venue, '/locale');
    Livewire::test(VenueProfile::class)->set('data.closures', $chiusure)->call('save')->assertHasNoErrors();
    // Ogni richiesta vera rilegge il locale; nel processo di test lo si rilegge a mano.
    $test->venue->refresh();
}

it('scrive le ferie nel profilo e le mostra sulla pagina pubblica del locale', function (): void {
    geChiudi($this, [['from' => '2026-09-21', 'to' => '2026-09-27', 'note' => 'Ferie di fine estate']]);

    expect($this->venue->fresh()->closures)->toBe([['from' => '2026-09-21', 'to' => '2026-09-27', 'note' => 'Ferie di fine estate']]);

    geSitoPubblico();
    $this->get('/locali/'.$this->venue->slug)->assertOk()
        ->assertSee(__('venues.detail.closures'))
        ->assertSee('Ferie di fine estate');
});

it('avvisa chi aggiunge una data nei giorni di chiusura, ma la salva e la pubblica', function (): void {
    geChiudi($this, [['from' => '2026-09-21', 'to' => '2026-09-27', 'note' => 'Ferie']]);
    $evento = $this->scenario->publishedEventA;

    geEntra($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    geDate($evento)
        ->mountTableAction('create')
        ->setTableActionData(['starts_at' => '2026-09-24 21:00:00'])
        ->assertMountedActionModalSee(__('manage.closures.warning'))
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $nuova = $evento->occurrences()->orderByDesc('starts_at')->first();
    expect(geOraLocale($nuova))->toBe('2026-09-24 21:00');

    geSitoPubblico();
    $this->get('/eventi/2026-09-24')->assertOk()->assertSee($evento->title);
});

it('avvisa chi sposta una data dentro una chiusura e tace fuori', function (): void {
    geChiudi($this, [['from' => '2026-10-05', 'to' => '2026-10-06', 'note' => null]]);
    $evento = $this->scenario->publishedEventA;
    $data = $this->scenario->occurrenceA;

    geEntra($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    geDate($evento)
        ->mountTableAction('edit', $data)
        ->setTableActionData(['starts_at' => '2026-10-05 21:00:00'])
        ->assertMountedActionModalSee(__('manage.closures.warning'))
        ->setTableActionData(['starts_at' => '2026-10-07 21:00:00'])
        ->assertMountedActionModalDontSee(__('manage.closures.warning'))
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect(geOraLocale($data->fresh()))->toBe('2026-10-07 21:00');
});

it('genera tutte le date di una serie anche attraverso una chiusura, avvisando solo nel modulo', function (): void {
    geChiudi($this, [['from' => '2026-09-21', 'to' => '2026-09-27', 'note' => 'Lavori']]);

    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, [
            'title' => 'Martedì acustici',
            'starts_at' => '2026-09-15 21:00:00',
            'repeat' => true,
            'repeat_frequency' => RecurrenceFrequency::Weekly->value,
            'repeat_weekdays' => [Weekday::Tuesday->value],
            'repeat_until' => '2026-09-29',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::query()->where('title', 'Martedì acustici')->sole()->occurrences()->orderBy('starts_at')->get()->map(geOraLocale(...))->all())
        ->toBe(['2026-09-15 21:00', '2026-09-22 21:00', '2026-09-29 21:00']);
});

it('non avvisa per le date di un evento che si tiene altrove', function (): void {
    geChiudi($this, [['from' => '2026-09-21', 'to' => '2026-09-27', 'note' => 'Ferie']]);
    $evento = $this->scenario->publishedEventA;
    $evento->update(['custom_location' => ['name' => 'Prato della Valle', 'address' => 'Prato della Valle, Padova', 'lat' => 45.3985, 'lng' => 11.8768]]);

    geEntra($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $evento->fresh(), 'pageClass' => EditEvent::class])
        ->mountTableAction('create')
        ->setTableActionData(['starts_at' => '2026-09-24 21:00:00'])
        ->assertMountedActionModalDontSee(__('manage.closures.warning'))
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($evento->occurrences()->count())->toBe(2);
});

it('dimentica da sola le chiusure finite al salvataggio successivo del profilo', function (): void {
    geChiudi($this, [
        ['from' => '2026-09-01', 'to' => '2026-09-05', 'note' => 'Già passata'],
        ['from' => '2026-12-24', 'to' => '2026-12-26', 'note' => 'Natale'],
    ]);
    expect($this->venue->fresh()->closures)->toBe([['from' => '2026-12-24', 'to' => '2026-12-26', 'note' => 'Natale']]);

    // Passato Natale, il primo salvataggio del profilo la toglie.
    freezeLocal($this->scenario->city, '2027-01-02 10:00');
    geEntra($this->owner, $this->venue, '/locale');
    Livewire::test(VenueProfile::class)->call('save')->assertHasNoErrors();
    expect($this->venue->fresh()->closures)->toBeIn([null, []]);

    geSitoPubblico();
    $this->get('/locali/'.$this->venue->slug)->assertOk()->assertDontSee('Natale');
});
