<?php

declare(strict_types=1);

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\ImportRunStatus;
use App\Enums\ImportSourceType;
use App\Enums\OccurrenceStatus;
use App\Enums\VerificationStatus;
use App\Exceptions\ImportException;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\ImportSource;
use App\Models\Venue;
use App\Services\Import\ImportRunner;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Tests\Support\IcsFixtures;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->source = ImportSource::factory()->create([
        'city_id' => $this->city->getKey(),
        'url' => IcsFixtures::URL,
        'default_category_id' => $this->category->getKey(),
    ]);

    // Tutte le date dei calendari di prova cadono da settembre 2026 in avanti.
    Carbon::setTestNow(localInstant($this->city, '2026-08-20 12:00:00'));
});

function runImport(?ImportSource $source = null): array
{
    return app(ImportRunner::class)->run($source ?? test()->source)->toArray();
}

// ------------------------------------------------------------- la scrittura

it('crea eventi pubblicati e non verificati', function (): void {
    IcsFixtures::fake('three-date-forms');

    $report = runImport();

    expect($report['created'])->toBe(3)
        ->and($report['updated'])->toBe(0)
        ->and($report['errors'])->toBe(0);

    $event = Event::query()->where('title', 'Data con TZID')->firstOrFail();

    // D32: pubblicazione diretta, ma dichiaratamente non verificata (§14.1).
    expect($event->status)->toBe(EventStatus::Published)
        ->and($event->source)->toBe(EventSource::ImportIcs)
        ->and($event->verification_status)->toBe(VerificationStatus::Unverified)
        ->and($event->published_at)->not->toBeNull()
        ->and($event->city_id)->toBe($this->city->getKey())
        ->and($event->slug)->toBe('data-con-tzid');

    $occurrence = $event->occurrences()->firstOrFail();

    expect($occurrence->starts_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-05 19:30:00')
        ->and($occurrence->status)->toBe(OccurrenceStatus::Scheduled)
        // Le colonne calcolate le scrive l'observer, anche quando la riga
        // arriva da un import (§8.2, §8.3).
        ->and($occurrence->business_date?->format('Y-m-d'))->toBe('2026-09-05')
        ->and($occurrence->effective_ends_at)->not->toBeNull();
});

it('scrive il luogo del calendario quando la sorgente non ha un locale', function (): void {
    IcsFixtures::fake('google-calendar');

    runImport();

    $event = Event::query()->where('title', 'Notte in vinile')->firstOrFail();

    expect($event->custom_location)->toBe(['name' => 'Via Roma 12, Padova'])
        ->and($event->venue_id)->toBeNull();
});

it('collega gli eventi al locale della sorgente invece che al luogo dichiarato', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
    $this->source->update(['venue_id' => $venue->getKey()]);

    IcsFixtures::fake('google-calendar');
    runImport();

    $event = Event::query()->where('title', 'Notte in vinile')->firstOrFail();

    expect($event->venue_id)->toBe($venue->getKey())
        ->and($event->custom_location)->toBeNull();
});

// ------------------------------------------------------------ l'idempotenza

it('non crea duplicati in tre esecuzioni consecutive', function (): void {
    IcsFixtures::fake('google-calendar');

    $first = runImport();
    $second = runImport();
    $third = runImport();

    expect($first['created'])->toBe(4)
        ->and($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(0)
        ->and($second['unchanged'])->toBe(4)
        ->and($third)->toBe($second)
        ->and(Event::query()->count())->toBe(4);
});

it('mantiene gli stessi identificativi e le stesse date fra un esecuzione e l altra', function (): void {
    IcsFixtures::fake('three-date-forms');

    runImport();
    $before = Event::query()->orderBy('id')->pluck('source_ref', 'id')->all();
    $dates = Event::query()->orderBy('id')->with('occurrences')->get()
        ->flatMap(fn (Event $event) => $event->occurrences->pluck('starts_at')->map(fn ($d) => $d?->format('c')))
        ->all();

    runImport();
    runImport();

    $after = Event::query()->orderBy('id')->pluck('source_ref', 'id')->all();
    $datesAfter = Event::query()->orderBy('id')->with('occurrences')->get()
        ->flatMap(fn (Event $event) => $event->occurrences->pluck('starts_at')->map(fn ($d) => $d?->format('c')))
        ->all();

    expect($after)->toBe($before)->and($datesAfter)->toBe($dates);
});

it('non fa collidere due sorgenti che dichiarano lo stesso UID', function (): void {
    $other = ImportSource::factory()->create([
        'city_id' => $this->city->getKey(),
        'url' => IcsFixtures::URL,
        'default_category_id' => $this->category->getKey(),
    ]);

    IcsFixtures::fake('three-date-forms');

    runImport();
    $second = runImport($other);

    // Stessi UID, sorgenti diverse: sei eventi, non tre sovrascritti.
    expect($second['created'])->toBe(3)
        ->and(Event::query()->count())->toBe(6);
});

// ------------------------------------------------------------ l'aggiornamento

it('aggiorna l evento esistente invece di crearne uno nuovo', function (): void {
    IcsFixtures::fakeEvents([
        "UID:spostato@locale.example\nSUMMARY:Concerto d'apertura\nDESCRIPTION:Prima versione.\nLOCATION:Sala grande\nDTSTAMP:20260801T090000Z\nDTSTART;TZID=Europe/Rome:20260905T210000\nDTEND;TZID=Europe/Rome:20260905T230000",
    ]);

    runImport();
    $event = Event::query()->firstOrFail();
    $id = $event->getKey();

    IcsFixtures::fakeEvents([
        "UID:spostato@locale.example\nSUMMARY:Concerto d'apertura (nuovo titolo)\nDESCRIPTION:Seconda versione.\nLOCATION:Sala piccola\nDTSTAMP:20260802T090000Z\nDTSTART;TZID=Europe/Rome:20260906T220000\nDTEND;TZID=Europe/Rome:20260907T000000",
    ]);

    $report = runImport();

    expect($report['updated'])->toBe(1)
        ->and($report['created'])->toBe(0)
        ->and(Event::query()->count())->toBe(1);

    $event = Event::query()->findOrFail($id);
    $occurrence = $event->occurrences()->firstOrFail();

    expect($event->title)->toBe("Concerto d'apertura (nuovo titolo)")
        ->and($event->description)->toBe('Seconda versione.')
        ->and($event->custom_location)->toBe(['name' => 'Sala piccola'])
        ->and($occurrence->starts_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-06 20:00:00')
        ->and($occurrence->ends_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-06 22:00:00')
        // La colonna calcolata segue la data nuova.
        ->and($occurrence->business_date?->format('Y-m-d'))->toBe('2026-09-06')
        ->and($event->occurrences()->count())->toBe(1);
});

it('non rimette in pubblicazione un evento ritirato dalla redazione', function (): void {
    IcsFixtures::fake('three-date-forms');
    runImport();

    $event = Event::query()->firstOrFail();
    $event->update(['status' => EventStatus::Draft, 'verification_status' => VerificationStatus::EditorialChecked]);

    runImport();
    $event->refresh();

    expect($event->status)->toBe(EventStatus::Draft)
        ->and($event->verification_status)->toBe(VerificationStatus::EditorialChecked);
});

it('non ripesca un evento cestinato', function (): void {
    IcsFixtures::fake('three-date-forms');
    runImport();

    Event::query()->firstOrFail()->delete();

    $report = runImport();

    expect($report['created'])->toBe(0)
        ->and(Event::query()->count())->toBe(2)
        ->and(Event::withTrashed()->count())->toBe(3);
});

it('annulla la data quando il calendario dichiara STATUS CANCELLED', function (): void {
    IcsFixtures::fakeEvents([
        "UID:annullabile@locale.example\nSUMMARY:Serata a rischio\nDTSTAMP:20260801T090000Z\nDTSTART;TZID=Europe/Rome:20260905T210000",
    ]);
    runImport();

    IcsFixtures::fakeEvents([
        "UID:annullabile@locale.example\nSUMMARY:Serata a rischio\nSTATUS:CANCELLED\nDTSTAMP:20260802T090000Z\nDTSTART;TZID=Europe/Rome:20260905T210000",
    ]);
    runImport();

    expect(Event::query()->firstOrFail()->occurrences()->firstOrFail()->status)
        ->toBe(OccurrenceStatus::Cancelled);
});

// ------------------------------------------------------------- le sparizioni

it('marca e non cancella un evento sparito dal feed', function (): void {
    IcsFixtures::fake('three-date-forms');
    runImport();

    IcsFixtures::fakeEvents([
        "UID:tzid@teatro.example\nSUMMARY:Data con TZID\nDTSTAMP:20260801T090000Z\nDTSTART;TZID=Europe/Rome:20260905T213000\nDTEND;TZID=Europe/Rome:20260905T233000",
    ]);

    $report = runImport();

    expect($report['cancelled'])->toBe(2)
        // Nessuna riga rimossa: una cancellazione automatica su un errore di
        // rete distruggerebbe dati veri.
        ->and(Event::query()->count())->toBe(3);

    $sparito = Event::query()->where('title', 'Data fluttuante')->firstOrFail();

    expect($sparito->occurrences()->firstOrFail()->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($sparito->trashed())->toBeFalse();
});

it('non annulla nulla quando anche una sola voce del feed non si e lasciata leggere', function (): void {
    IcsFixtures::fake('three-date-forms');
    runImport();

    IcsFixtures::fakeEvents([
        "UID:tzid@teatro.example\nSUMMARY:Data con TZID\nDTSTAMP:20260801T090000Z\nDTSTART;TZID=Europe/Rome:20260905T213000",
        "UID:senza-titolo@teatro.example\nDTSTAMP:20260801T090000Z\nDTSTART;TZID=Europe/Rome:20260906T213000",
    ]);

    $report = runImport();

    expect($report['errors'])->toBe(1)
        ->and($report['cancelled'])->toBe(0);

    expect(Event::query()->where('title', 'Data fluttuante')->firstOrFail()
        ->occurrences()->firstOrFail()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('lascia stare il passato quando un evento sparisce', function (): void {
    IcsFixtures::fakeEvents([
        "UID:passato@locale.example\nSUMMARY:Serata di luglio\nDTSTAMP:20260701T090000Z\nDTSTART;TZID=Europe/Rome:20260710T210000",
    ]);
    runImport();

    IcsFixtures::fake('empty-calendar');
    $report = runImport();

    expect($report['cancelled'])->toBe(0)
        ->and(Event::query()->firstOrFail()->occurrences()->firstOrFail()->status)
        ->toBe(OccurrenceStatus::Scheduled);
});

// ------------------------------------------------------------------ il filtro

it('tiene fuori le voci interne di un calendario', function (): void {
    IcsFixtures::fake('internal-entries');

    $report = runImport();

    expect($report['excluded'])->toBe(5)
        ->and($report['created'])->toBe(2)
        ->and(Event::query()->pluck('title')->sort()->values()->all())
        ->toBe(["Concerto del quartetto d'archi", "Rassegna d'arte contemporanea"]);
});

it('rispetta le parole di esclusione dichiarate dalla sorgente', function (): void {
    $this->source->update(['mapping' => ['exclude_keywords' => ['quartetto']]]);

    IcsFixtures::fake('internal-entries');

    $report = runImport();

    // L'elenco dichiarato sostituisce quello predefinito: passano anche le
    // riunioni, e resta fuori il solo quartetto.
    expect($report['excluded'])->toBe(1)
        ->and($report['created'])->toBe(6);
});

// ------------------------------------------------------------- le ricorrenze

it('passa le RRULE a GenerateOccurrencesAction', function (): void {
    IcsFixtures::fake('recurring');

    runImport();

    $event = Event::query()->firstOrFail();
    $recurrence = $event->recurrences()->firstOrFail();

    expect($recurrence->rrule)->toBe('FREQ=WEEKLY;BYDAY=TH;COUNT=6')
        ->and($recurrence->exdates)->toBe(['2026-09-17 21:30:00']);

    $starts = $event->occurrences()->orderBy('starts_at')->pluck('starts_at')
        ->map(fn ($date) => $date?->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->all();

    // Sei giovedi meno quello escluso.
    expect($starts)->toBe([
        '2026-09-03 21:30',
        '2026-09-10 21:30',
        '2026-09-24 21:30',
        '2026-10-01 21:30',
        '2026-10-08 21:30',
    ]);
});

it('non moltiplica le occorrenze di una serie a ogni esecuzione', function (): void {
    IcsFixtures::fake('recurring');

    runImport();
    runImport();
    runImport();

    expect(Event::query()->firstOrFail()->occurrences()->count())->toBe(5)
        ->and(Event::query()->firstOrFail()->recurrences()->count())->toBe(1);
});

it('annulla le date che la regola riscritta a monte non produce piu', function (): void {
    IcsFixtures::fake('recurring');
    runImport();

    IcsFixtures::fakeEvents([
        "UID:jam@circolo.example\nSUMMARY:Jam session del giovedi\nDTSTAMP:20260802T090000Z\nDTSTART;TZID=Europe/Rome:20260903T213000\nDTEND;TZID=Europe/Rome:20260903T233000\nRRULE:FREQ=WEEKLY;BYDAY=TH;COUNT=6\nEXDATE;TZID=Europe/Rome:20260917T213000\nEXDATE;TZID=Europe/Rome:20261001T213000",
    ]);

    $report = runImport();

    $event = Event::query()->firstOrFail();
    $cancellata = $event->occurrences()
        ->whereDate('starts_at', '2026-10-01')
        ->firstOrFail();

    expect($report['cancelled'])->toBe(1)
        ->and($cancellata->status)->toBe(OccurrenceStatus::Cancelled)
        // Le altre restano dove sono.
        ->and($event->occurrences()->where('status', OccurrenceStatus::Scheduled)->count())->toBe(4);
});

it('non tocca una data che qualcuno ha modificato a mano', function (): void {
    IcsFixtures::fake('recurring');
    runImport();

    $event = Event::query()->firstOrFail();
    $spostata = $event->occurrences()->orderBy('starts_at')->skip(2)->firstOrFail();
    $spostata->update(['starts_at' => $spostata->starts_at?->addDay()]);

    expect($spostata->fresh()?->is_exception)->toBeTrue();

    $report = runImport();

    // La regola non produce piu quella data, ma la decisione di una persona
    // vale piu della regola del feed (D21).
    expect($report['cancelled'])->toBe(0)
        ->and($spostata->fresh()?->status)->toBe(OccurrenceStatus::Scheduled);
});

it('annulla la serie quando il feed smette di dichiarare una regola', function (): void {
    IcsFixtures::fake('recurring');
    runImport();

    IcsFixtures::fakeEvents([
        "UID:jam@circolo.example\nSUMMARY:Jam session del giovedi\nDTSTAMP:20260802T090000Z\nDTSTART;TZID=Europe/Rome:20260903T213000\nDTEND;TZID=Europe/Rome:20260903T233000",
    ]);

    $report = runImport();
    $event = Event::query()->firstOrFail();

    expect($report['cancelled'])->toBe(4)
        ->and($event->occurrences()->where('status', OccurrenceStatus::Scheduled)->count())->toBe(1);
});

// --------------------------------------------------------------- i fallimenti

it('fallisce con grazia su un file malformato', function (): void {
    IcsFixtures::fake('malformed');

    expect(fn () => runImport())->toThrow(ImportException::class);

    $this->source->refresh();

    expect($this->source->last_status)->toBe(ImportRunStatus::Failed->value)
        ->and($this->source->last_error)->toContain('iCalendar')
        ->and($this->source->last_run_at)->not->toBeNull()
        ->and(Event::query()->count())->toBe(0);
});

it('riconosce una pagina di errore servita al posto del calendario', function (): void {
    IcsFixtures::fake('not-a-calendar');

    expect(fn () => runImport())->toThrow(ImportException::class);

    expect($this->source->fresh()?->last_error)->toBe(__('import.errors.not_a_calendar'));
});

it('riporta lo stato HTTP di un calendario che non c e piu', function (): void {
    IcsFixtures::fake('three-date-forms', 404);

    expect(fn () => runImport())->toThrow(ImportException::class);

    expect($this->source->fresh()?->last_error)->toContain('404');
});

it('riporta una rete che non risponde senza cancellare niente', function (): void {
    IcsFixtures::fake('three-date-forms');
    runImport();

    IcsFixtures::fakeFailure(new ConnectionException('Connection timed out'));

    expect(fn () => runImport())->toThrow(ImportException::class);

    expect(Event::query()->count())->toBe(3)
        ->and(Event::query()->firstOrFail()->occurrences()->firstOrFail()->status)
        ->toBe(OccurrenceStatus::Scheduled)
        ->and($this->source->fresh()?->last_status)->toBe(ImportRunStatus::Failed->value);
});

it('rifiuta una sorgente senza indirizzo', function (): void {
    $this->source->update(['url' => null]);

    expect(fn () => runImport())->toThrow(ImportException::class, __('import.errors.no_url'));
});

it('rifiuta una sorgente di un tipo senza driver', function (): void {
    $this->source->update(['type' => ImportSourceType::Rss]);

    expect(fn () => runImport())->toThrow(ImportException::class);
});

it('segnala le voci illeggibili senza fermare le altre', function (): void {
    IcsFixtures::fakeEvents([
        "UID:buona@locale.example\nSUMMARY:Serata regolare\nDTSTAMP:20260801T090000Z\nDTSTART;TZID=Europe/Rome:20260905T210000",
        "SUMMARY:Voce senza identificativo\nDTSTAMP:20260801T090000Z\nDTSTART;TZID=Europe/Rome:20260906T210000",
        "UID:senza-inizio@locale.example\nSUMMARY:Voce senza data\nDTSTAMP:20260801T090000Z",
    ]);

    $report = runImport();
    $this->source->refresh();

    expect($report['created'])->toBe(1)
        ->and($report['errors'])->toBe(2)
        ->and($this->source->last_status)->toBe(ImportRunStatus::Partial->value)
        ->and($this->source->last_error)->not->toBeNull();
});

it('registra l esito riuscito e ripulisce l errore precedente', function (): void {
    $this->source->update(['last_status' => ImportRunStatus::Failed->value, 'last_error' => 'guasto precedente']);

    IcsFixtures::fake('three-date-forms');
    runImport();

    $this->source->refresh();

    expect($this->source->last_status)->toBe(ImportRunStatus::Success->value)
        // La dashboard di §14.5 guarda last_error: senza la ripulitura la
        // sorgente resterebbe segnalata in guasto per sempre.
        ->and($this->source->last_error)->toBeNull()
        ->and($this->source->last_run_at)->not->toBeNull();
});

it('si ferma quando non c e alcuna categoria disponibile', function (): void {
    $this->source->update(['default_category_id' => null]);
    Category::query()->delete();

    IcsFixtures::fake('three-date-forms');

    expect(fn () => runImport())->toThrow(ImportException::class, __('import.errors.no_category'));
});

// ------------------------------------------------------------- l'anteprima

it('mostra le prime date senza scrivere niente', function (): void {
    IcsFixtures::fake('google-calendar');

    $preview = app(ImportRunner::class)->preview($this->source);

    expect($preview)->toHaveCount(4)
        ->and(Event::query()->count())->toBe(0)
        ->and($this->source->fresh()?->last_run_at)->toBeNull()
        // In ordine di inizio: e' cosi che si riconosce un fuso letto male.
        ->and($preview[0]->startsAt->lessThan($preview[3]->startsAt))->toBeTrue()
        ->and($preview[0]->title)->toBe('Notte in vinile');
});

it('applica il filtro di esclusione anche all anteprima', function (): void {
    IcsFixtures::fake('internal-entries');

    $preview = app(ImportRunner::class)->preview($this->source);

    expect($preview)->toHaveCount(2);
});

it('taglia l anteprima al numero di date richiesto', function (): void {
    IcsFixtures::fake('google-calendar');

    expect(app(ImportRunner::class)->preview($this->source, 2))->toHaveCount(2);
});

// ---------------------------------------------------------- l'isolamento città

it('scrive gli eventi nella citta della sorgente', function (): void {
    $altra = City::factory()->create(['name' => 'Vicenza', 'slug' => 'vicenza']);
    $source = ImportSource::factory()->create([
        'city_id' => $altra->getKey(),
        'url' => IcsFixtures::URL,
        'default_category_id' => $this->category->getKey(),
    ]);

    IcsFixtures::fake('three-date-forms');
    runImport($source);

    expect(Event::query()->where('city_id', $altra->getKey())->count())->toBe(3)
        ->and(Event::query()->where('city_id', $this->city->getKey())->count())->toBe(0);
});
