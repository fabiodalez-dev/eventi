<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Queries\EditorialDashboardQuery;
use Carbon\Carbon;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * §14.4 — i duplicati sospetti.
 *
 * La regola ha tre condizioni che devono valere **insieme**: stessa giornata
 * evento, stesso luogo (o due luoghi a meno di trecento metri), titoli quasi
 * identici. Ognuna delle tre viene qui messa alla prova da sola, perché un
 * rilevatore che scatta su due condizioni su tre riempie la dashboard di
 * coppie che non c'entrano nulla e smette di essere letto.
 *
 * L'ultima riga di §14.4 è la più importante e ha un test suo: **nessuna
 * cancellazione automatica**. Questo codice produce candidati; la decisione
 * resta a chi modera.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Un locale con coordinate scelte, per misurare i trecento metri di §14.4.
 */
function venueAtCoordinates(int $cityId, float $lat, float $lng): Venue
{
    return Venue::factory()->approved()->create([
        'city_id' => $cityId,
        'lat' => $lat,
        'lng' => $lng,
        'location' => new Point($lat, $lng, 0),
    ]);
}

/**
 * Una data pubblicata con titolo e locale scelti: è il mattone di ogni caso.
 */
function dataConTitolo(string $title, string $localStartsAt, ?Venue $venue = null): EventOccurrence
{
    return occurrenceAtLocal(
        test()->city,
        test()->category,
        $localStartsAt,
        event: ['title' => $title],
        venue: $venue ?? test()->venue,
    );
}

/**
 * @return list<int>
 */
function sospetti(): array
{
    $ids = EditorialDashboardQuery::for(test()->city)->possibleDuplicateIds();
    sort($ids);

    return $ids;
}

it('segnala due schede quasi identiche nello stesso locale e nella stessa giornata', function (): void {
    $prima = dataConTitolo('Concerto della Banda di Selvazzano', '2026-09-20 21:00');
    $seconda = dataConTitolo('Concerto della banda di Selvazzano', '2026-09-20 21:30');

    $attesi = [(int) $prima->event_id, (int) $seconda->event_id];
    sort($attesi);

    expect(sospetti())->toBe($attesi);
});

it('lascia in pace due serate diverse nello stesso locale', function (): void {
    dataConTitolo('Concerto della Banda di Selvazzano', '2026-09-20 21:00');
    dataConTitolo('Presentazione del libro sulle periferie', '2026-09-20 21:30');

    expect(sospetti())->toBe([]);
});

it('misura la somiglianza sulla soglia di §14.4 e non a occhio', function (): void {
    // Sopra la soglia: un titolo scritto due volte con una lettera di scarto.
    expect(EditorialDashboardQuery::titlesLookAlike(
        'Festa di quartiere all\'Arcella',
        'Festa di quartiere all Arcella',
    ))->toBeTrue();

    // Sotto la soglia: due feste, ma non la stessa.
    expect(EditorialDashboardQuery::titlesLookAlike(
        'Festa di quartiere all\'Arcella',
        'Festa di primavera al Portello',
    ))->toBeFalse();

    expect(EditorialDashboardQuery::DUPLICATE_SIMILARITY)->toBe(0.85);
});

it('non guarda le maiuscole né gli spazi ai bordi', function (): void {
    expect(EditorialDashboardQuery::titlesLookAlike('  SAGRA DEL BACCALÀ  ', 'sagra del baccalà'))->toBeTrue();
});

it('non dichiara simile un titolo vuoto, che sarebbe simile a qualunque cosa', function (): void {
    expect(EditorialDashboardQuery::titlesLookAlike('', ''))->toBeFalse()
        ->and(EditorialDashboardQuery::titlesLookAlike('   ', 'Concerto'))->toBeFalse();
});

it('non mette insieme due giornate diverse, nemmeno a poche ore di distanza', function (): void {
    dataConTitolo('Concerto della Banda di Selvazzano', '2026-09-20 21:00');
    dataConTitolo('Concerto della Banda di Selvazzano', '2026-09-21 21:00');

    expect(sospetti())->toBe([]);
});

it('riconosce la stessa sagra inserita sul circolo e sulla piazza davanti', function (): void {
    $circolo = venueAtCoordinates($this->city->getKey(), 45.4064, 11.8768);
    // Circa duecento metri più a nord: due righe di `venues`, un luogo solo.
    $piazza = venueAtCoordinates($this->city->getKey(), 45.4082, 11.8768);

    $prima = dataConTitolo('Sagra di fine estate', '2026-09-20 19:00', $circolo);
    $seconda = dataConTitolo('Sagra di fine estate', '2026-09-20 19:00', $piazza);

    $attesi = [(int) $prima->event_id, (int) $seconda->event_id];
    sort($attesi);

    expect(sospetti())->toBe($attesi);
});

it('non mette insieme due locali oltre i trecento metri', function (): void {
    $primo = venueAtCoordinates($this->city->getKey(), 45.4064, 11.8768);
    // Circa un chilometro più a nord: due sagre omonime in due quartieri.
    $secondo = venueAtCoordinates($this->city->getKey(), 45.4154, 11.8768);

    dataConTitolo('Sagra di fine estate', '2026-09-20 19:00', $primo);
    dataConTitolo('Sagra di fine estate', '2026-09-20 19:00', $secondo);

    /* Le due schede esistono, hanno la stessa giornata e lo stesso titolo:
       l'unica ragione per cui non sono sospette è la distanza. */
    expect(EventOccurrence::query()->distinct()->count('business_date'))->toBe(1)
        ->and(sospetti())->toBe([])
        ->and(EditorialDashboardQuery::DUPLICATE_DISTANCE_METERS)->toBe(300.0);
});

it('non guarda indietro: due copie di una serata già passata non sono più un problema', function (): void {
    dataConTitolo('Concerto della Banda di Selvazzano', '2026-08-20 21:00');
    dataConTitolo('Concerto della Banda di Selvazzano', '2026-08-20 21:30');

    expect(sospetti())->toBe([]);
});

it('ignora le date annullate e gli eventi archiviati', function (): void {
    $prima = dataConTitolo('Concerto della Banda di Selvazzano', '2026-09-20 21:00');
    $annullata = dataConTitolo('Concerto della Banda di Selvazzano', '2026-09-20 21:30');

    $annullata->status = OccurrenceStatus::Cancelled;
    $annullata->save();

    expect(sospetti())->toBe([]);

    // Stessa cosa se la copia esiste ancora ma l'evento è uscito dal catalogo.
    $annullata->status = OccurrenceStatus::Scheduled;
    $annullata->save();

    Event::query()->whereKey($annullata->event_id)->update(['status' => EventStatus::Archived]);

    expect(sospetti())->toBe([])
        ->and($prima->fresh())->not->toBeNull();
});

it('vede anche le bozze e le proposte in attesa, che è dove il doppione nasce', function (): void {
    $pubblicato = dataConTitolo('Concerto della Banda di Selvazzano', '2026-09-20 21:00');
    $bozza = dataConTitolo('Concerto della Banda di Selvazzano', '2026-09-20 21:30');

    Event::query()->whereKey($bozza->event_id)->update([
        'status' => EventStatus::Pending,
        'published_at' => null,
    ]);

    $attesi = [(int) $pubblicato->event_id, (int) $bozza->event_id];
    sort($attesi);

    expect(sospetti())->toBe($attesi);
});

it('non cancella niente: dopo il controllo le due schede sono ancora tutte e due lì', function (): void {
    $prima = dataConTitolo('Concerto della Banda di Selvazzano', '2026-09-20 21:00');
    $seconda = dataConTitolo('Concerto della banda di Selvazzano', '2026-09-20 21:30');

    $primaDelControllo = Event::query()->count();

    $sospetti = sospetti();

    expect($sospetti)->toHaveCount(2)
        ->and(Event::query()->count())->toBe($primaDelControllo)
        ->and(Event::withTrashed()->whereKey($prima->event_id)->firstOrFail()->deleted_at)->toBeNull()
        ->and(Event::withTrashed()->whereKey($seconda->event_id)->firstOrFail()->deleted_at)->toBeNull()
        ->and(EventOccurrence::query()->count())->toBe(2)
        /* Il flag di §14.4 è un giudizio, non un'esecuzione: lo stato delle
           due schede è rimasto quello che era. */
        ->and(Event::query()->whereKey($prima->event_id)->firstOrFail()->status)
        ->toBe(EventStatus::Published);
});

it('la coda della redazione mostra proprio quelle schede, e non altre', function (): void {
    $prima = dataConTitolo('Concerto della Banda di Selvazzano', '2026-09-20 21:00');
    $seconda = dataConTitolo('Concerto della banda di Selvazzano', '2026-09-20 21:30');
    dataConTitolo('Tutt\'altra cosa in programma', '2026-09-20 21:00');

    $ids = EditorialDashboardQuery::for($this->city)->possibleDuplicates()->pluck('id')->sort()->values()->all();

    $attesi = [(int) $prima->event_id, (int) $seconda->event_id];
    sort($attesi);

    /* Il numero del riquadro e le righe che si aprono cliccandolo nascono
       dallo stesso metodo: se divergessero, la dashboard mentirebbe. */
    expect($ids)->toBe($attesi)
        ->and(EditorialDashboardQuery::for($this->city)->possibleDuplicates()->count())->toBe(2);
});

it('non guarda oltre il confine della città', function (): void {
    $altraCitta = testCity(['name' => 'Vicenza', 'slug' => 'vicenza']);
    $altroLocale = Venue::factory()->approved()->create(['city_id' => $altraCitta->getKey()]);

    dataConTitolo('Concerto della Banda di Selvazzano', '2026-09-20 21:00');

    occurrenceAtLocal(
        $altraCitta,
        $this->category,
        '2026-09-20 21:00',
        event: ['title' => 'Concerto della Banda di Selvazzano', 'city_id' => $altraCitta->getKey()],
        venue: $altroLocale,
    );

    /* Due schede omonime nella stessa giornata: se il confine di città non
       contasse, sarebbero una coppia sospetta. */
    expect(Event::query()->count())->toBe(2)
        ->and(EventOccurrence::query()->distinct()->count('business_date'))->toBe(1)
        ->and(sospetti())->toBe([])
        ->and(EditorialDashboardQuery::for($altraCitta)->possibleDuplicateIds())->toBe([]);
});
