<?php

declare(strict_types=1);

use App\Services\Sponsorship\Delivery\TrafficForecast;
use Carbon\CarbonImmutable;

/*
 * La previsione del traffico: media pesata delle ultime quattro settimane,
 * meno di quattro settimane, curva di partenza scalata sui giorni misurati o
 * sul valore di configurazione, e la somma fra due istanti.
 */

/**
 * Una settimana con lo stesso valore in ogni ora, salvo quelle indicate.
 *
 * @param  array<int, int>  $hours
 * @return list<int>
 */
function p1Week(int $value, array $hours = []): array
{
    $week = array_fill(0, TrafficForecast::HOURS_PER_WEEK, $value);

    foreach ($hours as $hour => $views) {
        $week[$hour] = $views;
    }

    return $week;
}

it('weighs the last four weeks eight, four, two and one', function (): void {
    // Lunedì alle 20 (ora 20 della settimana): 30, 15, 60, 15 dalla più recente.
    $history = ['days' => 28, 'weeks' => [
        p1Week(10, [20 => 30]),
        p1Week(10, [20 => 15]),
        p1Week(10, [20 => 60]),
        p1Week(10, [20 => 15]),
    ]];

    $forecast = TrafficForecast::fromHistory($history, 400);

    expect($forecast->hourly(20))->toEqual((8 * 30 + 4 * 15 + 2 * 60 + 1 * 15) / 15)
        ->and($forecast->hourly(20))->toBe(29.0)
        ->and($forecast->hourly(21))->toBe(10.0)
        ->and($forecast->isProvisional())->toBeFalse()
        ->and($forecast->days())->toBe(28)
        ->and($forecast->weeklyTotal())->toBe(10.0 * 167 + 29.0)
        ->and($forecast->dailyAverage())->toBe((10.0 * 167 + 29.0) / 7);
});

it('gives the most recent week the most weight', function (): void {
    $rising = TrafficForecast::fromHistory(['days' => 28, 'weeks' => [p1Week(40), p1Week(10), p1Week(10), p1Week(10)]], 0);
    $falling = TrafficForecast::fromHistory(['days' => 28, 'weeks' => [p1Week(10), p1Week(10), p1Week(10), p1Week(40)]], 0);

    expect($rising->hourly(0))->toEqual((8 * 40 + 7 * 10) / 15)
        ->and($falling->hourly(0))->toEqual((14 * 10 + 40) / 15)
        ->and($rising->hourly(0))->toBeGreaterThan($falling->hourly(0));
});

it('reweighs with fewer than four complete weeks', function (): void {
    // 14 giorni: due settimane, (8·s1 + 4·s2) ÷ 12.
    $two = TrafficForecast::fromHistory(['days' => 14, 'weeks' => [p1Week(30), p1Week(60), null, null]], 400);

    // 20 giorni: la terza settimana ha sei giorni e non conta.
    $partial = TrafficForecast::fromHistory(['days' => 20, 'weeks' => [p1Week(30), p1Week(60), p1Week(5), null]], 400);

    // 21 giorni con una settimana vuota in mezzo: (8·s1 + 2·s3) ÷ 10.
    $hole = TrafficForecast::fromHistory(['days' => 21, 'weeks' => [p1Week(30), null, p1Week(80), null]], 400);

    expect($two->hourly(100))->toEqual((8 * 30 + 4 * 60) / 12)
        ->and($two->isProvisional())->toBeFalse()
        ->and($partial->hourly(100))->toBe(40.0)
        ->and($hole->hourly(100))->toEqual((8 * 30 + 2 * 80) / 10);
});

it('reads a missing hour of a measured week as zero', function (): void {
    $forecast = TrafficForecast::fromHistory(['days' => 14, 'weeks' => [[20 => 36], [20 => 12], null, null]], 400);

    expect($forecast->hourly(20))->toEqual((8 * 36 + 4 * 12) / 12)
        ->and($forecast->hourly(21))->toBe(0.0)
        ->and($forecast->weeklyTotal())->toEqual(28.0);
});

it('uses only as many weights as it is given', function (): void {
    $forecast = TrafficForecast::fromHistory(['days' => 28, 'weeks' => [p1Week(30), p1Week(60), p1Week(90), p1Week(120)]], 0, [1]);

    expect($forecast->hourly(5))->toBe(30.0);
});

it('falls back to the starting curve below the minimum days', function (): void {
    // Dieci giorni misurati, 3.000 visite in tutto: 300 al giorno.
    $history = ['days' => 10, 'weeks' => [p1Week(0, [10 => 1500]), p1Week(0, [40 => 1500]), null, null]];

    $forecast = TrafficForecast::fromHistory($history, 400);

    expect($forecast->isProvisional())->toBeTrue()
        ->and($forecast->days())->toBe(10)
        ->and($forecast->weeklyTotal())->toEqualWithDelta(2100, 1e-9)
        ->and($forecast->dailyAverage())->toEqualWithDelta(300, 1e-9);
});

it('honours a different minimum of days', function (): void {
    $history = ['days' => 7, 'weeks' => [p1Week(10), null, null, null]];

    expect(TrafficForecast::fromHistory($history, 400, minDays: 7)->isProvisional())->toBeFalse()
        ->and(TrafficForecast::fromHistory($history, 400, minDays: 7)->hourly(3))->toBe(10.0)
        ->and(TrafficForecast::fromHistory($history, 400)->isProvisional())->toBeTrue();
});

it('falls back to the scaled curve when no complete week is usable', function (): void {
    // Un minimo di 3 giorni con 5 giorni di dati: nessuna settimana intera.
    $forecast = TrafficForecast::fromHistory(['days' => 5, 'weeks' => [p1Week(10), null, null, null]], 400, minDays: 3);

    expect($forecast->isProvisional())->toBeTrue()
        ->and($forecast->dailyAverage())->toEqualWithDelta(10 * 168 / 5, 1e-9);
});

it('falls back to the scaled curve when the complete weeks are empty', function (): void {
    $forecast = TrafficForecast::fromHistory(['days' => 14, 'weeks' => [null, null, p1Week(1), null]], 250);

    expect($forecast->isProvisional())->toBeTrue()
        ->and($forecast->dailyAverage())->toEqualWithDelta(168 / 14, 1e-9);
});

it('uses the configured views when there is no data at all', function (): void {
    $forecast = TrafficForecast::fromHistory(['days' => 0, 'weeks' => [null, null, null, null]], 250);

    expect($forecast->isProvisional())->toBeTrue()
        ->and($forecast->days())->toBe(0)
        ->and($forecast->weeklyTotal())->toEqualWithDelta(1750, 1e-9)
        ->and(TrafficForecast::fromHistory(['days' => -3, 'weeks' => []], 60)->weeklyTotal())->toEqualWithDelta(420, 1e-9);
});

it('shapes the starting curve with lunch and evening peaks', function (): void {
    $forecast = TrafficForecast::startingCurve(400);
    $monday = fn (int $hour): float => $forecast->hourly($hour);

    expect($monday(12))->toBeGreaterThan($monday(10))
        ->and($monday(13))->toBeGreaterThan($monday(15))
        ->and($monday(19))->toBeGreaterThan($monday(13))
        ->and($monday(20))->toBe($monday(19))
        ->and($monday(3))->toBeLessThan($monday(12) / 10)
        ->and($forecast->weeklyTotal())->toEqualWithDelta(2800, 1e-9);
});

it('adds a quarter on friday and saturday in the starting curve', function (): void {
    $forecast = TrafficForecast::startingCurve(400);

    foreach ([12, 19] as $hour) {
        expect($forecast->hourly(4 * 24 + $hour))->toEqualWithDelta(1.25 * $forecast->hourly($hour), 1e-9)
            ->and($forecast->hourly(5 * 24 + $hour))->toEqualWithDelta(1.25 * $forecast->hourly($hour), 1e-9)
            ->and($forecast->hourly(6 * 24 + $hour))->toEqualWithDelta($forecast->hourly($hour), 1e-9)
            ->and($forecast->hourly(3 * 24 + $hour))->toEqualWithDelta($forecast->hourly($hour), 1e-9);
    }
});

it('never forecasts negative traffic', function (): void {
    expect(TrafficForecast::startingCurve(-50)->weeklyTotal())->toBe(0.0)
        ->and(TrafficForecast::flat(-50)->weeklyTotal())->toBe(0.0)
        ->and(TrafficForecast::fromProfile(p1Week(-3, [7 => 12]))->weeklyTotal())->toBe(12.0);
});

it('rejects a week that is not 168 hours', function (): void {
    TrafficForecast::fromProfile(array_fill(0, 167, 1));
})->throws(InvalidArgumentException::class);

it('keeps the hours, days and flag of a written profile', function (): void {
    $forecast = TrafficForecast::fromProfile(p1Week(2, [167 => 9]), 21, true);

    expect($forecast->profile())->toHaveCount(168)
        ->and($forecast->hourly(167))->toBe(9.0)
        ->and($forecast->hourly(168))->toBe(2.0)
        ->and($forecast->hourly(-1))->toBe(9.0)
        ->and($forecast->hourly(-169))->toBe(9.0)
        ->and($forecast->days())->toBe(21)
        ->and($forecast->isProvisional())->toBeTrue()
        ->and(TrafficForecast::fromProfile(p1Week(1), -2)->days())->toBe(0)
        ->and(TrafficForecast::fromProfile(p1Week(1))->days())->toBe(0)
        ->and(TrafficForecast::startingCurve(400)->days())->toBe(0)
        ->and(TrafficForecast::startingCurve(400, -5)->days())->toBe(0)
        ->and(TrafficForecast::startingCurve(400, 9)->days())->toBe(9)
        // Le chiavi non contano, conta l'ordine: 168 valori dal lunedì alle 0.
        ->and(TrafficForecast::fromProfile(array_combine(range(1, 168), p1Week(2, [0 => 7])))->hourly(0))->toBe(7.0)
        ->and(TrafficForecast::fromProfile(p1Week(1))->isProvisional())->toBeFalse();
});

it('marks a flat forecast as provisional with no days', function (): void {
    $forecast = TrafficForecast::flat(240);

    expect($forecast->hourly(0))->toBe(10.0)
        ->and($forecast->hourly(167))->toBe(10.0)
        ->and($forecast->profile())->toBe(array_fill(0, 168, 10.0))
        ->and($forecast->days())->toBe(0)
        ->and($forecast->isProvisional())->toBeTrue();
});

it('numbers the hours of the week from monday midnight in local time', function (): void {
    expect(TrafficForecast::hourOfWeek(CarbonImmutable::parse('2026-11-23 00:30', 'Europe/Rome')))->toBe(0)
        ->and(TrafficForecast::hourOfWeek(CarbonImmutable::parse('2026-11-25 13:00', 'Europe/Rome')))->toBe(2 * 24 + 13)
        ->and(TrafficForecast::hourOfWeek(CarbonImmutable::parse('2026-11-29 23:59', 'Europe/Rome')))->toBe(167)
        // Lo stesso istante in UTC è ancora domenica sera.
        ->and(TrafficForecast::hourOfWeek(CarbonImmutable::parse('2026-11-23 00:30', 'Europe/Rome')->utc()))->toBe(167);
});

it('sums whole and partial hours between two instants', function (): void {
    $forecast = TrafficForecast::flat(240);
    $monday = CarbonImmutable::parse('2026-11-23 10:30', 'Europe/Rome');

    expect($forecast->between($monday, $monday->setTime(12, 15)))->toEqualWithDelta(17.5, 1e-9)
        ->and($forecast->between($monday, $monday->addMinutes(20)))->toEqualWithDelta(10 / 3, 1e-9)
        ->and($forecast->between($monday, $monday->addDays(10)))->toEqualWithDelta(2400, 1e-9)
        ->and($forecast->between($monday, $monday->addWeeks(52)))->toEqualWithDelta(240 * 7 * 52, 1e-6)
        ->and($forecast->between($monday, $monday))->toBe(0.0)
        ->and($forecast->between($monday->addHour(), $monday))->toBe(0.0);
});

it('reads each hour of the week in the right place', function (): void {
    // Solo il lunedì dalle 0 alle 1 e la domenica dalle 23 alle 24 hanno traffico.
    $forecast = TrafficForecast::fromProfile(p1Week(0, [0 => 100, 167 => 50]));
    $mondayMidnight = CarbonImmutable::parse('2026-11-23 00:00', 'Europe/Rome');

    expect($forecast->between($mondayMidnight, $mondayMidnight->addHour()))->toBe(100.0)
        ->and($forecast->between($mondayMidnight->subHour(), $mondayMidnight))->toBe(50.0)
        ->and($forecast->between($mondayMidnight->subMinutes(30), $mondayMidnight->addMinutes(15)))->toEqualWithDelta(25 + 25, 1e-9)
        ->and($forecast->between($mondayMidnight->addHour(), $mondayMidnight->addDays(6)))->toBe(0.0)
        // In UTC lo stesso intervallo è ancora la domenica dalle 23 alle 24.
        ->and($forecast->between($mondayMidnight->utc(), $mondayMidnight->addHour()->utc()))->toBe(50.0);
});

it('counts the hours of the wall clock on a daylight saving day', function (): void {
    $forecast = TrafficForecast::flat(240);
    $springDay = CarbonImmutable::parse('2026-03-29 00:00', 'Europe/Rome');
    $autumnDay = CarbonImmutable::parse('2026-10-25 00:00', 'Europe/Rome');

    expect($springDay->diffInHours($springDay->addDay()))->toBe(23.0)
        ->and($forecast->between($springDay, $springDay->addDay()))->toEqualWithDelta(240, 1e-9)
        ->and($forecast->between($autumnDay, $autumnDay->addDay()))->toEqualWithDelta(240, 1e-9);
});

it('works before 1970 too', function (): void {
    $forecast = TrafficForecast::fromProfile(p1Week(0, [0 => 100]));
    $monday = CarbonImmutable::parse('1969-12-29 00:00', 'UTC');

    expect($forecast->between($monday, $monday->addHour()))->toBe(100.0)
        ->and($forecast->between($monday->subWeeks(3)->addMinutes(30), $monday->subWeeks(3)->addHour()))->toBe(50.0)
        ->and($forecast->between($monday->subWeeks(3), $monday))->toBe(300.0);
});

it('somma ora per ora le previsioni di più città, con i giorni della più povera', function (): void {
    $a = TrafficForecast::fromProfile(p1Week(2, [10 => 6]), 20, false);
    $b = TrafficForecast::fromProfile(p1Week(1), 9, true);
    $somma = TrafficForecast::sum([$a, $b]);

    expect($somma->hourly(10))->toBe(7.0)
        ->and($somma->hourly(11))->toBe(3.0)
        ->and($somma->weeklyTotal())->toBe($a->weeklyTotal() + $b->weeklyTotal())
        ->and($somma->days())->toBe(9)
        ->and($somma->isProvisional())->toBeTrue()
        ->and(TrafficForecast::sum([$a, $a])->isProvisional())->toBeFalse()
        ->and(TrafficForecast::sum([$a, $a])->days())->toBe(20)
        ->and(TrafficForecast::sum([$b, $a])->days())->toBe(9)
        ->and(TrafficForecast::sum([$b, $a])->isProvisional())->toBeTrue()
        ->and(TrafficForecast::sum([$a]))->toBe($a);
});

it('non somma un elenco vuoto', function (): void {
    TrafficForecast::sum([]);
})->throws(InvalidArgumentException::class);
