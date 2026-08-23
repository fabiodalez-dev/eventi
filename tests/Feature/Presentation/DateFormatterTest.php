<?php

declare(strict_types=1);

use App\Support\DateFormatter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * `DateFormatter` formatta, non classifica: qui si verifica che le parole
 * siano quelle italiane giuste e che il fuso della città non sposti mai una
 * giornata di un giorno.
 */
beforeEach(function (): void {
    $this->formatter = DateFormatter::forTimezone('Europe/Rome');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('legge un istante UTC nell\'ora della città', function (): void {
    // 19:30 UTC in agosto sono le 21:30 a Roma (ora legale).
    expect($this->formatter->time(CarbonImmutable::parse('2026-09-05 19:30:00', 'UTC')))->toBe('21:30');

    // 20:30 UTC in dicembre sono le 21:30 (ora solare): lo scarto cambia.
    expect($this->formatter->time(CarbonImmutable::parse('2026-12-05 20:30:00', 'UTC')))->toBe('21:30');
});

it('nomina la giornata in forma relativa quando è vicina', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-05 12:00:00', 'Europe/Rome'));

    expect($this->formatter->day(CarbonImmutable::parse('2026-09-05', 'UTC')))->toBe('oggi')
        ->and($this->formatter->day(CarbonImmutable::parse('2026-09-06', 'UTC')))->toBe('domani')
        ->and($this->formatter->day(CarbonImmutable::parse('2026-09-04', 'UTC')))->toBe('ieri')
        ->and($this->formatter->day(CarbonImmutable::parse('2026-09-11', 'UTC')))->toBe('venerdì 11 settembre');
});

it('aggiunge l\'anno soltanto quando non è quello corrente', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-05 12:00:00', 'Europe/Rome'));

    expect($this->formatter->weekdayDate(CarbonImmutable::parse('2026-09-11', 'UTC')))->toBe('venerdì 11 settembre')
        ->and($this->formatter->weekdayDate(CarbonImmutable::parse('2027-01-08', 'UTC')))->toBe('venerdì 8 gennaio 2027');
});

/*
 * La trappola: `business_date` ha il cast `date` e arriva a mezzanotte UTC.
 * Convertirla in Europe/Rome la porterebbe alle 22:00 del giorno prima.
 */
it('non sposta di un giorno una giornata letta da business_date', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 12:00:00', 'Europe/Rome'));

    $businessDate = CarbonImmutable::parse('2026-09-05 00:00:00', 'UTC');

    expect($this->formatter->weekdayDate($businessDate))->toBe('sabato 5 settembre')
        ->and($this->formatter->isoDay($businessDate))->toBe('2026-09-05');
});

it('tiene distinte la serata e l\'ora di un after dopo la mezzanotte', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 12:00:00', 'Europe/Rome'));

    // Serata di venerdì 4, inizio all'01:30 di sabato 5 (§8.2).
    $businessDate = CarbonImmutable::parse('2026-09-04 00:00:00', 'UTC');
    $startsAt = CarbonImmutable::parse('2026-09-04 23:30:00', 'UTC');

    expect($this->formatter->dayAndTime($businessDate, $startsAt))->toBe('venerdì 4 settembre alle 01:30');
});

it('scrive la giornata da sola quando non c\'è un orario', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-05 12:00:00', 'Europe/Rome'));

    expect($this->formatter->dayAndTime(CarbonImmutable::parse('2026-09-06', 'UTC')))->toBe('domani');
});

it('usa la forma serale solo quando gliela si chiede', function (): void {
    expect($this->formatter->tonightAt(CarbonImmutable::parse('2026-09-05 19:30:00', 'UTC')))
        ->toBe('stasera alle 21:30');
});

it('accorcia il conto alla rovescia per i badge', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-05 20:00:00', 'Europe/Rome'));

    $in = fn (int $minutes): string => $this->formatter->countdown(
        CarbonImmutable::parse('2026-09-05 20:00:00', 'Europe/Rome')->addMinutes($minutes),
    );

    expect($in(25))->toBe('25 min')
        ->and($in(120))->toBe('2 h')
        ->and($in(80))->toBe('1 h 20 min');
});

it('scrive il conto alla rovescia per esteso', function (): void {
    $now = CarbonImmutable::parse('2026-09-05 20:00:00', 'Europe/Rome');
    Carbon::setTestNow($now);

    expect($this->formatter->relative($now->addMinutes(25)))->toBe('tra 25 minuti')
        ->and($this->formatter->relative($now->addMinute()))->toBe('tra 1 minuto')
        ->and($this->formatter->relative($now->addHours(2)))->toBe('tra 2 ore')
        ->and($this->formatter->relative($now->addDays(3)))->toBe('tra 3 giorni')
        ->and($this->formatter->relative($now->subMinute()))->toBe('adesso');
});

it('formatta un intervallo di orari, anche quando la fine non è nota', function (): void {
    $start = CarbonImmutable::parse('2026-09-05 19:30:00', 'UTC');
    $end = CarbonImmutable::parse('2026-09-05 21:45:00', 'UTC');

    expect($this->formatter->timeRange($start, $end))->toBe('21:30 – 23:45')
        ->and($this->formatter->timeRange($start))->toBe('dalle 21:30');
});

it('espone le forme leggibili da una macchina con lo scarto del fuso', function (): void {
    expect($this->formatter->iso(CarbonImmutable::parse('2026-09-05 19:30:00', 'UTC')))
        ->toBe('2026-09-05T21:30:00+02:00');
});

it('prende il fuso dalla città, non dal server', function (): void {
    $instant = CarbonImmutable::parse('2026-09-05 19:30:00', 'UTC');

    expect(DateFormatter::forTimezone('Europe/Rome')->time($instant))->toBe('21:30')
        ->and(DateFormatter::forTimezone('UTC')->time($instant))->toBe('19:30');
});
