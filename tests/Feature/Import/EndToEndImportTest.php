<?php

declare(strict_types=1);

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\VerificationStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\ImportSource;
use App\Services\Import\ImportRunner;
use Carbon\Carbon;
use Tests\Support\IcsFixtures;

/**
 * La prova d'insieme dell'import: un calendario vero, letto per intero, tre
 * volte di fila.
 *
 * I test accanto a questo verificano i pezzi. Questo verifica che il percorso
 * completo — scarica, interpreta i fusi, filtra, scrive, e poi **si rilegge
 * senza raddoppiare** — faccia ciò che serve al prodotto. È il test che si
 * rompe per primo quando qualcuno tocca il driver credendo di migliorarlo.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();

    $this->source = ImportSource::factory()->create([
        'city_id' => $this->city->getKey(),
        'url' => IcsFixtures::URL,
        'default_category_id' => $this->category->getKey(),
        'is_active' => true,
    ]);

    Carbon::setTestNow(localInstant($this->city, '2026-08-20 12:00:00'));
});

/*
 * Le tre forme di data di un file ICS descrivono, in questo calendario, lo
 * STESSO istante: le 21:30 del 5 settembre a Roma, che sono le 19:30 UTC
 * perché in settembre vige l'ora legale.
 *
 * È la verifica che vale più di tutte le altre in questa suite. Lo stack
 * tecnologico chiama i fusi degli ICS «la prima fonte di bug in ogni sistema
 * di calendario», e il modo in cui il bug si manifesta è sempre questo: gli
 * eventi entrano, la pagina non dà errore, e le ore sono sbagliate di due.
 */
it('interpreta le tre forme di data come lo stesso istante', function (): void {
    IcsFixtures::fake('three-date-forms');

    app(ImportRunner::class)->run($this->source);

    $occurrences = EventOccurrence::query()
        ->with('event')
        ->get()
        ->sortBy(fn (EventOccurrence $o): string => $o->event->title)
        ->values();

    expect($occurrences)->toHaveCount(3);

    foreach ($occurrences as $occurrence) {
        expect($occurrence->starts_at->utc()->format('Y-m-d H:i'))
            ->toBe('2026-09-05 19:30', "forma: {$occurrence->event->title}")
            ->and($occurrence->starts_at->setTimezone($this->city->timezone)->format('H:i'))
            ->toBe('21:30', "forma: {$occurrence->event->title}");
    }
});

it('pubblica gli eventi importati marcandoli come non verificati', function (): void {
    IcsFixtures::fake('three-date-forms');

    app(ImportRunner::class)->run($this->source);

    $event = Event::query()->firstOrFail();

    // D32: pubblicazione diretta, decisione del committente contro §14.2.
    // §14.1 resta però in vigore: la provenienza è dichiarata, così il sito e
    // l'API possono distinguere un evento confermato dal locale da uno letto
    // da un calendario e mai verificato da nessuno.
    expect($event->status)->toBe(EventStatus::Published)
        ->and($event->source)->toBe(EventSource::ImportIcs)
        ->and($event->verification_status)->toBe(VerificationStatus::Unverified);
});

it('non pubblica le voci interne del calendario', function (): void {
    IcsFixtures::fake('internal-entries');

    $report = app(ImportRunner::class)->run($this->source);

    $titles = Event::query()->pluck('title')->all();

    // Entrano i due eventi veri.
    expect($titles)->toContain("Concerto del quartetto d'archi")
        ->and($titles)->toContain("Rassegna d'arte contemporanea");

    // Restano fuori le cinque voci di servizio.
    expect($titles)->not->toContain('Riunione staff')
        ->and($titles)->not->toContain('Chiuso per ferie')
        ->and($titles)->not->toContain('Manutenzione impianto audio')
        ->and($titles)->not->toContain('Evento privato - compleanno')
        ->and($titles)->not->toContain('Chiusura estiva');

    expect($report->toArray()['excluded'])->toBe(5);
});

/*
 * L'import gira ogni ora, per sempre. Un difetto di idempotenza non si vede
 * il primo giorno: si vede il mese dopo, quando lo stesso concerto compare
 * settecento volte e il catalogo è da buttare.
 */
it('non raddoppia niente rileggendo lo stesso calendario tre volte', function (): void {
    IcsFixtures::fake('three-date-forms');

    $runner = app(ImportRunner::class);

    $runner->run($this->source);

    $eventiDopoLaPrima = Event::query()->count();
    $dateDopoLaPrima = EventOccurrence::query()->count();

    $runner->run($this->source);
    $runner->run($this->source);

    expect(Event::query()->count())->toBe($eventiDopoLaPrima)
        ->and(EventOccurrence::query()->count())->toBe($dateDopoLaPrima);
});

it('aggiorna l evento esistente quando il calendario cambia a monte', function (): void {
    IcsFixtures::fake('three-date-forms');

    $runner = app(ImportRunner::class);
    $runner->run($this->source);

    $primoGiro = Event::query()->count();
    $id = Event::query()->where('title', 'Data con TZID')->value('id');

    // Stesso UID, titolo diverso: è una modifica, non un evento nuovo.
    IcsFixtures::fake('three-date-forms-renamed');
    $report = $runner->run($this->source);

    expect(Event::query()->count())->toBe($primoGiro)
        ->and(Event::query()->find($id)?->title)->toBe('Data con TZID, titolo corretto')
        ->and($report->toArray()['updated'])->toBeGreaterThan(0)
        ->and($report->toArray()['created'])->toBe(0);
});
