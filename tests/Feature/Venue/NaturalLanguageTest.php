<?php

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\ScheduleShortcut;
use App\Enums\StatsPeriod;
use App\Enums\Weekday;
use App\Support\RecurrenceRule;
use Carbon\CarbonImmutable;

/**
 * §10.4 — «il gestore non deve mai vedere la sintassi RRULE».
 *
 * Il traduttore fra la frase e lo standard è un punto solo, e va nei due
 * versi: quello che si costruisce si deve poter rileggere, altrimenti riaprire
 * il modulo mostrerebbe una regola diversa da quella salvata.
 */
it('costruisce la regola dalla frase del gestore', function (RecurrenceFrequency $frequency, array $weekdays, string $expected): void {
    expect(RecurrenceRule::build($frequency, $weekdays))->toBe($expected);
})->with([
    'ogni giorno' => [RecurrenceFrequency::Daily, [], 'FREQ=DAILY'],
    'ogni settimana, giorno della prima data' => [RecurrenceFrequency::Weekly, [], 'FREQ=WEEKLY'],
    'ogni giovedì' => [RecurrenceFrequency::Weekly, ['thu'], 'FREQ=WEEKLY;BYDAY=TH'],
    'una settimana sì e una no' => [RecurrenceFrequency::Biweekly, ['fri'], 'FREQ=WEEKLY;INTERVAL=2;BYDAY=FR'],
    'ogni mese' => [RecurrenceFrequency::Monthly, ['mon'], 'FREQ=MONTHLY'],
]);

it('mette i giorni in ordine di settimana, non in quello in cui sono stati toccati', function (): void {
    expect(RecurrenceRule::build(RecurrenceFrequency::Weekly, ['sun', 'wed', 'fri']))
        ->toBe('FREQ=WEEKLY;BYDAY=WE,FR,SU');
});

it('ignora i giorni che non esistono invece di scrivere una regola rotta', function (): void {
    expect(RecurrenceRule::build(RecurrenceFrequency::Weekly, ['thu', 'lunedi']))
        ->toBe('FREQ=WEEKLY;BYDAY=TH');
});

it('rilegge la regola come l’aveva detta il gestore', function (): void {
    $parsed = RecurrenceRule::parse('FREQ=WEEKLY;INTERVAL=2;BYDAY=TH,SA');

    expect($parsed['frequency'])->toBe(RecurrenceFrequency::Biweekly)
        ->and($parsed['weekdays'])->toBe(['thu', 'sat']);
});

it('descrive la regola a parole, mai con la sintassi', function (): void {
    $description = RecurrenceRule::describe('FREQ=WEEKLY;BYDAY=TH');

    expect($description)->toContain(mb_strtolower(RecurrenceFrequency::Weekly->label()))
        ->and($description)->toContain('giovedì')
        ->and($description)->not->toContain('FREQ')
        ->and($description)->not->toContain('BYDAY');
});

/*
 * ------------------------------------------------------------- scorciatoie
 */

it('propone stasera all’ora abituale del locale', function (): void {
    $now = CarbonImmutable::parse('2026-09-10 15:00:00', 'Europe/Rome');

    expect(ScheduleShortcut::Tonight->startsAt($now, 21, 30)->format('Y-m-d H:i'))
        ->toBe('2026-09-10 21:30');
});

it('propone domani alla stessa ora', function (): void {
    $now = CarbonImmutable::parse('2026-09-10 15:00:00', 'Europe/Rome');

    expect(ScheduleShortcut::Tomorrow->startsAt($now, 21, 0)->format('Y-m-d H:i'))
        ->toBe('2026-09-11 21:00');
});

it('propone il prossimo venerdì, e oggi stesso se oggi è venerdì', function (): void {
    // 2026-09-10 è un giovedì: il venerdì è il giorno dopo.
    $thursday = CarbonImmutable::parse('2026-09-10 10:00:00', 'Europe/Rome');
    expect(ScheduleShortcut::Friday->startsAt($thursday, 22, 0)->toDateString())->toBe('2026-09-11');

    // Di venerdì mattina, "venerdì" vuol dire stasera, non fra sette giorni.
    $friday = CarbonImmutable::parse('2026-09-11 10:00:00', 'Europe/Rome');
    expect(ScheduleShortcut::Friday->startsAt($friday, 22, 0)->toDateString())->toBe('2026-09-11');
});

it('accende la ripetizione solo per «ogni giovedì»', function (): void {
    expect(ScheduleShortcut::EveryThursday->repeats())->toBeTrue()
        ->and(ScheduleShortcut::EveryThursday->weekday())->toBe(Weekday::Thursday)
        ->and(ScheduleShortcut::Tonight->repeats())->toBeFalse()
        ->and(ScheduleShortcut::Tonight->weekday())->toBeNull();
});

/*
 * ------------------------------------------------------------- etichette
 */

it('ha un’etichetta italiana per ogni voce dei nuovi enum', function (): void {
    $labels = [
        ...array_values(RecurrenceFrequency::options()),
        ...array_values(StatsPeriod::options()),
        ...array_values(ScheduleShortcut::options()),
        ...array_values(Weekday::options()),
    ];

    foreach ($labels as $label) {
        expect($label)->not->toContain('.')
            ->and($label)->not->toBe('');
    }
});

it('mantiene le opzioni come mappa di stringhe', function (): void {
    foreach (array_keys(StatsPeriod::options()) as $key) {
        expect($key)->toBeString();
    }
});
