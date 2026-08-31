<?php

declare(strict_types=1);

use App\DTOs\ImportedEventDto;
use App\Models\ImportSource;
use App\Services\Import\IcsImportDriver;
use Carbon\CarbonImmutable;
use Tests\Support\IcsFixtures;

/**
 * «La prima fonte di bug in ogni sistema di calendario» sono i fusi orari, e
 * questo file è il posto in cui si dimostra che non lo sono qui.
 */

/**
 * @return list<ImportedEventDto>
 */
function mapFixture(string $fixture, array $sourceAttributes = []): array
{
    $city = testCity();
    $source = ImportSource::factory()->create([
        'city_id' => $city->getKey(),
        'url' => IcsFixtures::URL,
        ...$sourceAttributes,
    ]);

    IcsFixtures::fake($fixture);

    $driver = app(IcsImportDriver::class);
    $mapped = [];

    foreach ($driver->fetch($source) as $raw) {
        $dto = $driver->map($raw, $source);

        if ($dto !== null) {
            $mapped[] = $dto;
        }
    }

    return $mapped;
}

/**
 * @param  list<ImportedEventDto>  $dtos
 */
function byUid(array $dtos, string $uid): ImportedEventDto
{
    foreach ($dtos as $dto) {
        if ($dto->uid === $uid) {
            return $dto;
        }
    }

    throw new RuntimeException("Nessuna voce con UID {$uid}.");
}

it('legge una data fluttuante nel fuso della citta', function (): void {
    $dto = byUid(mapFixture('three-date-forms'), 'floating@teatro.example');

    // DTSTART:20260905T213000 — nessun fuso: sono le 21:30 di Padova.
    expect($dto->startsAt->format('Y-m-d H:i:s'))->toBe('2026-09-05 19:30:00')
        ->and($dto->startsAt->timezoneName)->toBe('UTC')
        ->and($dto->isAllDay)->toBeFalse();
});

it('legge una data in UTC senza convertirla due volte', function (): void {
    $dto = byUid(mapFixture('three-date-forms'), 'utc@teatro.example');

    // DTSTART:20260905T193000Z — gia UTC, e ci resta.
    expect($dto->startsAt->format('Y-m-d H:i:s'))->toBe('2026-09-05 19:30:00');
});

it('converte una data con TZID dal fuso dichiarato', function (): void {
    $dto = byUid(mapFixture('three-date-forms'), 'tzid@teatro.example');

    // DTSTART;TZID=Europe/Rome:20260905T213000 — le 21:30 di Roma, ora legale.
    expect($dto->startsAt->format('Y-m-d H:i:s'))->toBe('2026-09-05 19:30:00');
});

it('fa convergere le tre forme sullo stesso istante', function (): void {
    $dtos = mapFixture('three-date-forms');

    $instants = array_map(
        static fn (ImportedEventDto $dto): string => $dto->startsAt->format('Y-m-d H:i:s'),
        $dtos,
    );

    expect($dtos)->toHaveCount(3)
        ->and(array_unique($instants))->toHaveCount(1);
});

it('legge una data fluttuante nel fuso dichiarato dal mapping invece che in quello della citta', function (): void {
    $dto = byUid(
        mapFixture('three-date-forms', ['mapping' => ['timezone' => 'Europe/London']]),
        'floating@teatro.example',
    );

    // Le 21:30 di Londra sono le 20:30 UTC, non le 19:30.
    expect($dto->startsAt->format('Y-m-d H:i:s'))->toBe('2026-09-05 20:30:00');
});

it('riconosce VALUE=DATE come evento di intera giornata', function (): void {
    $dto = byUid(mapFixture('all-day-and-duration'), 'allday-single@comune.example');

    expect($dto->isAllDay)->toBeTrue()
        // Mezzanotte del 12 settembre a Padova, non mezzanotte UTC.
        ->and($dto->startsAt->format('Y-m-d H:i:s'))->toBe('2026-09-11 22:00:00')
        ->and($dto->startsAt->setTimezone('Europe/Rome')->format('Y-m-d H:i:s'))->toBe('2026-09-12 00:00:00')
        // DTEND di un evento di un giorno solo e' il giorno dopo: preso alla
        // lettera raddoppierebbe la durata. Qui la fine la decide §8.3.
        ->and($dto->endsAt)->toBeNull();
});

it('tratta il DTEND di un evento di piu giorni come esclusivo', function (): void {
    $dto = byUid(mapFixture('all-day-and-duration'), 'allday-span@comune.example');

    // 18-20 settembre: DTEND dichiara il 21, la fiera finisce il 20 a mezzanotte.
    expect($dto->isAllDay)->toBeTrue()
        ->and($dto->endsAt?->setTimezone('Europe/Rome')->format('Y-m-d H:i:s'))->toBe('2026-09-20 23:59:59');
});

it('accetta DURATION al posto di DTEND', function (): void {
    $dto = byUid(mapFixture('all-day-and-duration'), 'duration@comune.example');

    // 18:00 di Roma piu PT1H30M.
    expect($dto->startsAt->format('H:i'))->toBe('16:00')
        ->and($dto->endsAt?->format('Y-m-d H:i:s'))->toBe('2026-09-25 17:30:00');
});

it('lascia la fine vuota quando mancano sia DTEND sia DURATION', function (): void {
    $dto = byUid(mapFixture('all-day-and-duration'), 'no-end@comune.example');

    // §8.3: senza ora di fine la durata la decide la categoria.
    expect($dto->endsAt)->toBeNull();
});

it('risolve un TZID attraverso il VTIMEZONE incorporato nel file', function (): void {
    $dtos = mapFixture('google-calendar');
    $notte = byUid($dtos, '6b3k9m1p4q7r2s5t8u0v3w6x@google.com');

    // 4 settembre alle 22:00 di Roma, ora legale: 20:00 UTC.
    expect($notte->startsAt->format('Y-m-d H:i:s'))->toBe('2026-09-04 20:00:00')
        ->and($notte->endsAt?->format('Y-m-d H:i:s'))->toBe('2026-09-04 23:30:00');
});

it('applica lo scarto giusto prima e dopo il cambio dell ora', function (): void {
    $dtos = mapFixture('google-calendar');

    $legale = byUid($dtos, '6b3k9m1p4q7r2s5t8u0v3w6x@google.com');
    $solare = byUid($dtos, '1a2b3c4d5e6f7g8h9i0j1k2l@google.com');

    // 4 settembre: ora legale, +2. 1 novembre: ora solare, +1. Uno scarto
    // fisso sbaglierebbe di un'ora una delle due.
    expect($legale->startsAt->setTimezone('Europe/Rome')->format('H:i'))->toBe('22:00')
        ->and($solare->startsAt->format('Y-m-d H:i:s'))->toBe('2026-11-01 20:00:00')
        ->and($solare->startsAt->setTimezone('Europe/Rome')->format('H:i'))->toBe('21:00');
});

it('tiene distinta l eccezione di una serie dalla serie stessa', function (): void {
    $dtos = mapFixture('google-calendar');

    $serie = null;
    $eccezione = null;

    foreach ($dtos as $dto) {
        if ($dto->uid !== 'serie-aperitivo@google.com') {
            continue;
        }

        $dto->recurrenceId === null ? $serie = $dto : $eccezione = $dto;
    }

    expect($serie)->not->toBeNull()
        ->and($eccezione)->not->toBeNull()
        // Stesso UID, chiavi diverse: senza il RECURRENCE-ID l'eccezione
        // sovrascriverebbe la serie a ogni esecuzione.
        ->and($serie?->key())->toBe('serie-aperitivo@google.com')
        ->and($eccezione?->key())->toBe('serie-aperitivo@google.com#20260924T170000Z')
        ->and($serie?->isRecurring())->toBeTrue()
        ->and($eccezione?->isRecurring())->toBeFalse();
});

it('legge RRULE, EXDATE e UNTIL', function (): void {
    $dto = byUid(mapFixture('recurring'), 'jam@circolo.example');

    expect($dto->rrule)->toBe('FREQ=WEEKLY;BYDAY=TH;COUNT=6')
        // Le EXDATE si riscrivono in ora locale: e' cosi che
        // GenerateOccurrencesAction le rilegge.
        ->and($dto->exdates)->toBe(['2026-09-17 21:30:00'])
        ->and($dto->until)->toBeNull();
});

it('legge un UNTIL in UTC dentro la RRULE', function (): void {
    $dto = byUid(mapFixture('google-calendar'), 'serie-aperitivo@google.com');

    expect($dto->until?->format('Y-m-d H:i:s'))->toBe('2026-10-08 16:59:59');
});

it('legge le date sempre in UTC qualunque sia il fuso del processo', function (): void {
    // Un server con date.timezone diverso da UTC non deve cambiare le risposte.
    $previous = date_default_timezone_get();
    date_default_timezone_set('America/New_York');

    try {
        $dto = byUid(mapFixture('three-date-forms'), 'floating@teatro.example');

        expect($dto->startsAt)->toBeInstanceOf(CarbonImmutable::class)
            ->and($dto->startsAt->format('Y-m-d H:i:s'))->toBe('2026-09-05 19:30:00');
    } finally {
        date_default_timezone_set($previous);
    }
});
