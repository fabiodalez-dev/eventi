<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Filament\Venue\Pages\CalendarImport;
use App\Filament\Venue\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\ImportSource;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\IcsFixtures;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge il calendario collegato dal pannello del locale (§14.2, D32): le
 * date entrano pubblicate dopo l'anteprima, restano del locale, si spostano
 * e si annullano quando cambia il calendario d'origine, e smettono di
 * arrivare quando il collegamento si sospende o si toglie — senza portarsi
 * via quelle già pubblicate.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    freezeLocal($this->scenario->city, '2026-09-10 12:00');
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** Il calendario del locale, con le voci scritte come le scrive Google. */
function geCalendario(array $voci): void
{
    IcsFixtures::fakeEvents(array_map(fn (array $voce): string => implode("\n", [
        'UID:'.$voce['uid'],
        'DTSTAMP:20260901T080000Z',
        'DTSTART;TZID=Europe/Rome:'.$voce['inizio'],
        'DTEND;TZID=Europe/Rome:'.$voce['fine'],
        'SUMMARY:'.$voce['titolo'],
        'LOCATION:Circolo Aurora\, Padova',
        ...(isset($voce['stato']) ? ['STATUS:'.$voce['stato']] : []),
    ]), $voci));
}

function geCollegaCalendario(object $test): void
{
    geEntra($test->owner, $test->venue, '/calendario');
    Livewire::test(CalendarImport::class)
        ->fillForm(['url' => IcsFixtures::URL])
        ->call('preview')
        ->assertSet('previewed', true)
        ->call('connect')
        ->assertNotified(__('manage.calendar.notifications.connected'));
}

const GE_VOCI = [
    ['uid' => 'ge-milonga@calendario.test', 'inizio' => '20260918T213000', 'fine' => '20260918T235900', 'titolo' => 'Milonga del venerdì'],
    ['uid' => 'ge-coro@calendario.test', 'inizio' => '20260923T190000', 'fine' => '20260923T210000', 'titolo' => 'Prove aperte del coro'],
];

it('collega il calendario dal pannello: dopo l’anteprima le date sono online, del locale e segnate come importate', function (): void {
    geCalendario(GE_VOCI);
    geCollegaCalendario($this);

    $milonga = Event::query()->where('title', 'Milonga del venerdì')->sole();
    expect($milonga->venue_id)->toBe($this->venue->id)
        ->and($milonga->status)->toBe(EventStatus::Published)
        ->and($milonga->source)->toBe(EventSource::ImportIcs)
        ->and(geOraLocale($milonga->occurrences()->sole()))->toBe('2026-09-18 21:30')
        ->and(Event::query()->where('venue_id', $this->scenario->venueB->id)->where('source', EventSource::ImportIcs)->exists())->toBeFalse();

    geEntra($this->owner, $this->venue, '/eventi');
    Livewire::test(ListEvents::class)
        ->assertCanSeeTableRecords([$milonga])
        ->assertSee(EventSource::ImportIcs->label());

    geSitoPubblico();
    $this->get('/eventi/2026-09-18')->assertOk()->assertSee('Milonga del venerdì');
    $this->get('/eventi/'.$milonga->slug)->assertOk()->assertSee('Circolo Aurora');
});

it('sposta sul sito la data spostata nel calendario d’origine, senza doppioni', function (): void {
    geCalendario(GE_VOCI);
    geCollegaCalendario($this);
    $coro = Event::query()->where('title', 'Prove aperte del coro')->sole()->occurrences()->sole();

    geCalendario([GE_VOCI[0], [...GE_VOCI[1], 'inizio' => '20260925T190000', 'fine' => '20260925T210000']]);
    geEntra($this->owner, $this->venue, '/calendario');
    Livewire::test(CalendarImport::class)->callAction('runNow')->assertNotified(__('manage.calendar.notifications.run_done'));

    expect(EventOccurrence::query()->whereHas('event', fn ($q) => $q->where('title', 'Prove aperte del coro'))->count())->toBe(1)
        ->and(geOraLocale($coro->fresh()))->toBe('2026-09-25 19:00');

    geSitoPubblico();
    $this->get('/eventi/2026-09-23')->assertOk()->assertDontSee('Prove aperte del coro');
    $this->get('/eventi/2026-09-25')->assertOk()->assertSee('Prove aperte del coro');
});

it('annulla sul sito la data che sparisce dal calendario d’origine, e la dice annullata', function (): void {
    geCalendario(GE_VOCI);
    geCollegaCalendario($this);
    $milonga = Event::query()->where('title', 'Milonga del venerdì')->sole();

    geCalendario([GE_VOCI[1]]);
    geEntra($this->owner, $this->venue, '/calendario');
    Livewire::test(CalendarImport::class)->callAction('runNow');

    expect($milonga->occurrences()->sole()->status)->toBe(OccurrenceStatus::Cancelled);
    geSitoPubblico();
    $this->get('/eventi/'.$milonga->slug)->assertOk()->assertSee(OccurrenceStatus::Cancelled->label());
});

it('smette di leggere il calendario quando il referente sospende il collegamento, e riprende alla riattivazione', function (): void {
    geCalendario(GE_VOCI);
    geCollegaCalendario($this);

    Livewire::test(CalendarImport::class)->callAction('suspend')->assertNotified(__('manage.calendar.notifications.suspended'));

    geCalendario([...GE_VOCI, ['uid' => 'ge-nuova@calendario.test', 'inizio' => '20261002T210000', 'fine' => '20261002T230000', 'titolo' => 'Serata aggiunta dopo']]);
    $this->travel(2)->hours();
    $this->artisan('import:run')->assertSuccessful();
    expect(Event::query()->where('title', 'Serata aggiunta dopo')->exists())->toBeFalse();

    geEntra($this->owner, $this->venue, '/calendario');
    Livewire::test(CalendarImport::class)->callAction('suspend')->assertNotified(__('manage.calendar.notifications.resumed'));
    $this->travel(2)->hours();
    $this->artisan('import:run')->assertSuccessful();
    expect(Event::query()->where('title', 'Serata aggiunta dopo')->sole()->venue_id)->toBe($this->venue->id);
});

it('scollega il calendario senza togliere dal sito le date già pubblicate', function (): void {
    geCalendario(GE_VOCI);
    geCollegaCalendario($this);

    Livewire::test(CalendarImport::class)->callAction('disconnect')->assertNotified(__('manage.calendar.notifications.disconnected'));
    expect(ImportSource::query()->where('venue_id', $this->venue->id)->exists())->toBeFalse()
        ->and(Event::query()->where('venue_id', $this->venue->id)->where('source', EventSource::ImportIcs)->count())->toBe(2);

    geSitoPubblico();
    $this->get('/eventi/2026-09-18')->assertOk()->assertSee('Milonga del venerdì');
    $this->get('/eventi/2026-09-23')->assertOk()->assertSee('Prove aperte del coro');
});

it('lascia al gestore annullare a mano una data importata, che resta annullata alla lettura seguente', function (): void {
    geCalendario(GE_VOCI);
    geCollegaCalendario($this);
    $milonga = Event::query()->where('title', 'Milonga del venerdì')->sole();
    $data = $milonga->occurrences()->sole();

    geEntra($this->owner, $this->venue, '/eventi/'.$milonga->getRouteKey().'/modifica');
    geDate($milonga)->callTableAction('cancel_occurrence', $data)->assertHasNoTableActionErrors();
    expect($data->fresh()->status)->toBe(OccurrenceStatus::Cancelled);

    // Il calendario d'origine non è cambiato: la rilettura non deve rimettere in programma la serata.
    geEntra($this->owner, $this->venue, '/calendario');
    Livewire::test(CalendarImport::class)->callAction('runNow');
    expect($data->fresh()->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($milonga->occurrences()->count())->toBe(1);
});
