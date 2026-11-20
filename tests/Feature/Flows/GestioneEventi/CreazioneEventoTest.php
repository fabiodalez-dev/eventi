<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoGestioneEventi.php';

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\RecurrenceFrequency;
use App\Enums\Weekday;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\ListEvents;
use App\Filament\Venue\Resources\Events\Pages\ViewEvent;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Support\VenueEventDefaults;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Number;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/*
 * Protegge la nascita di un evento dal pannello del locale: il modulo rapido
 * con una data sola, le date aggiunte dopo, le serate che si ripetono, la
 * bozza ripresa e pubblicata. Ogni test segue l'evento fino a dove lo
 * incontra il pubblico — la scheda, la pagina del giorno, quella del locale —
 * perché un evento salvato bene ma invisibile è un evento perso.
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

it('crea un evento a data singola e lo porta su scheda, giornata e pagina del locale', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/nuovo');

    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, ['title' => 'Concerto d’autunno in cortile', 'description' => '<p>Quartetto d’archi sotto il portico.</p>']))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified(__('manage.notifications.published'));

    $evento = Event::query()->where('title', 'Concerto d’autunno in cortile')->sole();
    $data = $evento->occurrences()->sole();

    expect($evento->status)->toBe(EventStatus::Published)
        ->and($evento->source)->toBe(EventSource::Venue)
        ->and($evento->published_at)->not->toBeNull()
        ->and($evento->city_id)->toBe($this->scenario->city->id)
        ->and(geOraLocale($data))->toBe('2026-09-17 21:00')
        ->and($data->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($data->business_date->toDateString())->toBe('2026-09-17');

    // Nel pannello compare nell'elenco del locale, con la prossima data.
    $this->get(EventResource::getUrl('index'))->assertOk()->assertSee('Concerto d’autunno in cortile');

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertOk()
        ->assertSee('Concerto d’autunno in cortile')
        ->assertSee('Quartetto d’archi sotto il portico.')
        ->assertSee('Circolo Aurora');
    $this->get('/eventi/2026-09-17')->assertOk()->assertSee('Concerto d’autunno in cortile');
    $this->get('/eventi/2026-09-18')->assertOk()->assertDontSee('Concerto d’autunno in cortile');
    $this->get('/locali/'.$this->venue->slug)->assertOk()->assertSee('Concerto d’autunno in cortile');
});

it('aggiunge altre date dalla sezione «Date» e ognuna ha la sua pagina pubblica', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, ['title' => 'Rassegna di cinema muto', 'starts_at' => '2026-09-15 20:30:00']))
        ->call('create')
        ->assertHasNoFormErrors();
    $evento = Event::query()->where('title', 'Rassegna di cinema muto')->sole();

    geEntra($this->owner, $this->venue, '/eventi/'.$evento->getRouteKey().'/modifica');
    geDate($evento)
        ->callTableAction('create', data: ['starts_at' => '2026-09-22 20:30:00'])
        ->assertHasNoTableActionErrors()
        ->callTableAction('create', data: ['starts_at' => '2026-09-29 21:00:00', 'ends_at' => '2026-09-29 23:00:00'])
        ->assertHasNoTableActionErrors();

    $date = $evento->occurrences()->orderBy('starts_at')->get();
    expect($date->map(geOraLocale(...))->all())->toBe(['2026-09-15 20:30', '2026-09-22 20:30', '2026-09-29 21:00'])
        ->and($date->pluck('url_number')->unique())->toHaveCount(3)
        ->and($date[2]->ends_at?->setTimezone('Europe/Rome')->format('H:i'))->toBe('23:00')
        // Date aggiunte a mano, non generate da una regola.
        ->and($date->every(fn (EventOccurrence $o): bool => $o->recurrence_id === null))->toBeTrue();

    geSitoPubblico();
    foreach ($date as $o) {
        $this->get('/eventi/'.$evento->slug.'/'.$o->url_number)->assertOk()->assertSee('Rassegna di cinema muto');
    }
    $this->get('/eventi/2026-09-22')->assertOk()->assertSee('Rassegna di cinema muto');
    $this->get('/eventi/'.$evento->slug.'/999')->assertNotFound();
});

it('mette in calendario una serata che si ripete due giorni a settimana', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, [
            'title' => 'Corso di tango',
            'starts_at' => '2026-09-15 20:00:00',
            'repeat' => true,
            'repeat_frequency' => RecurrenceFrequency::Weekly->value,
            'repeat_weekdays' => [Weekday::Tuesday->value, Weekday::Thursday->value],
            'repeat_until' => '2026-09-30',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $evento = Event::query()->where('title', 'Corso di tango')->sole();
    $date = $evento->occurrences()->orderBy('starts_at')->get();

    expect($evento->recurrences()->sole()->rrule)->toBe('FREQ=WEEKLY;BYDAY=TU,TH')
        ->and($date->map(geOraLocale(...))->all())->toBe([
            '2026-09-15 20:00', '2026-09-17 20:00', '2026-09-22 20:00', '2026-09-24 20:00', '2026-09-29 20:00',
        ]);

    // La scheda nel pannello dice la regola a parole, non la stringa RFC 5545.
    $this->get(EventResource::getUrl('view', ['record' => $evento]))->assertOk()->assertDontSee('FREQ=WEEKLY');

    geSitoPubblico();
    $this->get('/eventi/2026-09-24')->assertOk()->assertSee('Corso di tango');
    $this->get('/eventi/2026-09-23')->assertOk()->assertDontSee('Corso di tango');
    $this->get('/eventi/2026-10-01')->assertOk()->assertDontSee('Corso di tango');
});

it('tiene l’ora locale di una serie che attraversa il ritorno all’ora solare', function (): void {
    freezeLocal($this->scenario->city, '2026-10-12 10:00');
    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, [
            'title' => 'Giovedì della milonga',
            'starts_at' => '2026-10-15 21:00:00',
            'repeat' => true,
            'repeat_frequency' => RecurrenceFrequency::Weekly->value,
            'repeat_weekdays' => [Weekday::Thursday->value],
            'repeat_until' => '2026-11-05',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $date = Event::query()->where('title', 'Giovedì della milonga')->sole()->occurrences()->orderBy('starts_at')->get();

    // Il 25 ottobre l'Italia torna all'ora solare: le 21 restano le 21, cambia l'UTC.
    expect($date->map(geOraLocale(...))->all())->toBe(['2026-10-15 21:00', '2026-10-22 21:00', '2026-10-29 21:00', '2026-11-05 21:00'])
        ->and($date->map(fn (EventOccurrence $o): string => $o->starts_at->utc()->format('H:i'))->all())->toBe(['19:00', '19:00', '20:00', '20:00']);
});

it('ripete ogni due settimane e ogni mese con le date giuste', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, [
            'title' => 'Mercatino quindicinale',
            'starts_at' => '2026-09-12 10:00:00',
            'repeat' => true,
            'repeat_frequency' => RecurrenceFrequency::Biweekly->value,
            'repeat_weekdays' => [],
            'repeat_until' => '2026-10-31',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, [
            'title' => 'Cena sociale del mese',
            'starts_at' => '2026-09-18 20:00:00',
            'repeat' => true,
            'repeat_frequency' => RecurrenceFrequency::Monthly->value,
            'repeat_until' => '2026-12-31',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $quindicinale = Event::query()->where('title', 'Mercatino quindicinale')->sole()->occurrences()->orderBy('starts_at')->get();
    $mensile = Event::query()->where('title', 'Cena sociale del mese')->sole()->occurrences()->orderBy('starts_at')->get();

    expect($quindicinale->map(geOraLocale(...))->all())->toBe(['2026-09-12 10:00', '2026-09-26 10:00', '2026-10-10 10:00', '2026-10-24 10:00'])
        ->and($mensile->map(geOraLocale(...))->all())->toBe(['2026-09-18 20:00', '2026-10-18 20:00', '2026-11-18 20:00', '2026-12-18 20:00']);
});

it('salva una bozza senza data, la riprende, aggiunge una data e la pubblica dalla scheda', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(['title' => 'Festa di fine estate'])
        ->call('saveDraftNow')
        ->assertNotified(__('manage.quick.draft_saved'));

    $bozza = Event::query()->where('title', 'Festa di fine estate')->sole();
    expect($bozza->status)->toBe(EventStatus::Draft)
        ->and($bozza->occurrences()->exists())->toBeFalse();

    // In elenco c'è, segnata come bozza; senza date non si può pubblicare.
    $this->get(EventResource::getUrl('index'))->assertOk()->assertSee('Festa di fine estate');
    Livewire::test(ListEvents::class)->assertCanSeeTableRecords([$bozza]);
    Livewire::test(ViewEvent::class, ['record' => $bozza->getRouteKey()])->assertActionDisabled('publish');

    geSitoPubblico();
    $this->get('/eventi/'.$bozza->slug)->assertNotFound();

    geEntra($this->owner, $this->venue, '/eventi/'.$bozza->getRouteKey().'/modifica');
    geDate($bozza)->callTableAction('create', data: ['starts_at' => '2026-09-19 18:00:00'])->assertHasNoTableActionErrors();

    geEntra($this->owner, $this->venue, '/eventi/'.$bozza->getRouteKey());
    Livewire::test(ViewEvent::class, ['record' => $bozza->getRouteKey()])
        ->assertActionEnabled('publish')
        ->callAction('publish')
        ->assertNotified(__('manage.notifications.published'));

    expect($bozza->fresh()->status)->toBe(EventStatus::Published);
    geSitoPubblico();
    $this->get('/eventi/'.$bozza->slug)->assertOk()->assertSee('Festa di fine estate');
    $this->get('/eventi/2026-09-19')->assertOk()->assertSee('Festa di fine estate');
});

it('scrive il prezzo e il biglietto sulla scheda pubblica come il gestore li ha inseriti', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, [
            'title' => 'Opera in forma di concerto',
            'price_type' => PriceType::Ticket->value,
            'price_min' => '12',
            'price_max' => '25',
            'ticket_url' => 'https://biglietti.example.test/opera',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $evento = Event::query()->where('title', 'Opera in forma di concerto')->sole();
    expect((float) $evento->price_min)->toBe(12.0)
        ->and((float) $evento->price_max)->toBe(25.0)
        ->and($evento->ticket_url)->toBe('https://biglietti.example.test/opera');

    geSitoPubblico();
    $this->get('/eventi/'.$evento->slug)->assertOk()
        ->assertSee(Number::currency(12, in: 'EUR', locale: 'it', precision: 0))
        ->assertSee(Number::currency(25, in: 'EUR', locale: 'it', precision: 0))
        ->assertSee('https://biglietti.example.test/opera', false);
});

it('ricorda categoria, prezzo e ora del primo evento e le propone al secondo', function (): void {
    $teatro = testCategory(['name' => 'Teatro di prosa']);

    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($teatro, [
            'title' => 'Prima della stagione',
            'starts_at' => '2026-09-18 20:45:00',
            'price_type' => PriceType::Ticket->value,
            'price_min' => '15',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(VenueEventDefaults::startTime($this->venue->fresh()))->toBe([20, 45]);

    // Il secondo evento nasce già compilato con le abitudini del locale.
    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->assertSchemaStateSet([
            'category_id' => $teatro->id,
            'price_type' => PriceType::Ticket->value,
        ]);
});

it('rifiuta una fine prima dell’inizio e non lascia un evento a metà online', function (): void {
    geEntra($this->owner, $this->venue, '/eventi/nuovo');
    Livewire::test(CreateEvent::class)
        ->fillForm(geDatiEvento($this->scenario->category, [
            'title' => 'Serata al contrario',
            'starts_at' => '2026-09-17 21:00:00',
            'ends_at' => '2026-09-17 19:00:00',
        ]))
        ->call('create')
        ->assertHasFormErrors(['ends_at']);

    expect(Event::query()->where('title', 'Serata al contrario')->where('status', EventStatus::Published)->exists())->toBeFalse()
        ->and(EventOccurrence::query()->whereHas('event', fn ($q) => $q->where('title', 'Serata al contrario'))->exists())->toBeFalse();
});
