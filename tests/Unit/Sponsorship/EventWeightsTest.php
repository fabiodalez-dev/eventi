<?php

declare(strict_types=1);

use App\Services\Sponsorship\Delivery\EventWeights;
use Carbon\CarbonImmutable;

/*
 * Quale evento mostrare dentro la stessa unità: la vicinanza della prossima
 * data, con il pavimento, gli eventi senza date future esclusi e il contesto
 * della pagina.
 */

function p1Now(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Rome');
}

it('gives 62, 31 and 7 percent to events in 1, 6 and 20 days', function (): void {
    $now = p1Now();
    $weights = new EventWeights;

    $dates = ['a' => $now->addDays(1), 'b' => $now->addDays(6), 'c' => $now->addDays(20)];

    $raw = $weights->weights($dates, $now);
    $shares = $weights->shares($dates, $now);

    expect(round($raw['a'], 3))->toBe(0.867)
        ->and(round($raw['b'], 3))->toBe(0.424)
        ->and($raw['c'])->toBe(0.1)
        ->and(round($shares['a'], 2))->toBe(0.62)
        ->and(round($shares['b'], 2))->toBe(0.31)
        ->and(round($shares['c'], 2))->toBe(0.07)
        ->and(array_sum($shares))->toEqualWithDelta(1.0, 1e-12);
});

it('never lets an event fall below the floor', function (): void {
    $weights = new EventWeights;

    // Senza pavimento fra 20 giorni sarebbe 0,057, fra cento quasi zero.
    expect(exp(-20 / 7))->toBeLessThan(0.1)
        ->and($weights->proximity(20))->toBe(0.1)
        ->and($weights->proximity(100))->toBe(0.1)
        ->and($weights->proximity(16.2))->toBe(0.1)
        ->and($weights->proximity(16.0))->toBeGreaterThan(0.1);
});

it('weighs an event happening now as one', function (): void {
    $now = p1Now();
    $weights = new EventWeights;

    expect($weights->proximity(0))->toBe(1.0)
        ->and($weights->proximity(-3))->toBe(1.0)
        ->and($weights->weights(['live' => $now], $now))->toBe(['live' => 1.0]);
});

it('counts fractions of a day', function (): void {
    $now = p1Now();
    $weights = new EventWeights;

    expect($weights->weights(['tonight' => $now->addHours(12)], $now)['tonight'])->toEqualWithDelta(exp(-0.5 / 7), 1e-12);
});

it('leaves out events without a future date', function (): void {
    $now = p1Now();
    $weights = new EventWeights;

    $shares = $weights->shares([
        'past' => $now->subMinute(),
        'none' => null,
        'next' => $now->addDays(3),
    ], $now);

    expect($shares)->toBe(['next' => 1.0]);
});

it('returns nothing when no event can be shown', function (): void {
    $now = p1Now();
    $weights = new EventWeights;

    expect($weights->weights(['past' => $now->subDay(), 'none' => null], $now))->toBe([])
        ->and($weights->shares(['past' => $now->subDay()], $now))->toBe([])
        ->and($weights->shares([], $now))->toBe([]);
});

it('keeps only the events that match the page', function (): void {
    $now = p1Now();
    $weights = new EventWeights;
    $dates = [10 => $now->addDays(1), 11 => $now->addDays(6), 12 => $now->addDays(20)];

    $shares = $weights->shares($dates, $now, [11, 12, 99]);

    expect(array_keys($shares))->toBe([11, 12])
        ->and($shares[11])->toEqualWithDelta(exp(-6 / 7) / (exp(-6 / 7) + 0.1), 1e-12);
});

it('goes back to every event when none matches the page', function (): void {
    $now = p1Now();
    $weights = new EventWeights;
    $dates = [10 => $now->addDays(1), 11 => $now->addDays(6)];

    expect(array_keys($weights->shares($dates, $now, [99])))->toBe([10, 11])
        ->and(array_keys($weights->shares($dates, $now, [])))->toBe([10, 11])
        // Un evento del contesto ma senza date future non conta come «trovato».
        ->and(array_keys($weights->shares($dates + [13 => null], $now, [13])))->toBe([10, 11]);
});

it('follows the configured constant and floor', function (): void {
    $weights = new EventWeights(14, 0.25);

    expect($weights->proximity(7))->toEqualWithDelta(exp(-0.5), 1e-12)
        ->and($weights->proximity(30))->toBe(0.25);
});

it('keeps the constant and floor in a sane range', function (): void {
    expect((new EventWeights(0, 0.1))->proximity(1))->toBe(0.1)
        ->and((new EventWeights(0, 0.1))->proximity(1 / 48))->toEqualWithDelta(exp(-0.5), 1e-12)
        ->and((new EventWeights(7, 2.0))->proximity(0))->toBe(1.0)
        ->and((new EventWeights(7, 2.0))->proximity(30))->toBe(1.0)
        ->and((new EventWeights(7, -1))->proximity(1000))->toBe(exp(-1000 / 7));
});

it('normalises weights to one', function (): void {
    expect(EventWeights::normalise(['a' => 3.0, 'b' => 1.0]))->toBe(['a' => 0.75, 'b' => 0.25])
        ->and(EventWeights::normalise([]))->toBe([])
        ->and(EventWeights::normalise(['a' => 0.0]))->toBe([]);
});

it('carries a value from the events to the unit as a weighted mean', function (): void {
    // L'affinità di chi guarda: 4 per l'evento del locale seguito, 1 per l'altro.
    $shares = ['a' => 0.75, 'b' => 0.25];

    expect(EventWeights::weightedMean($shares, ['a' => 4, 'b' => 1]))->toBe(3.25)
        ->and(EventWeights::weightedMean($shares, ['a' => 4]))->toBe(3.25)
        ->and(EventWeights::weightedMean($shares, [], 2.0))->toBe(2.0)
        ->and(EventWeights::weightedMean([], ['a' => 4]))->toBe(1.0)
        ->and(EventWeights::weightedMean(['a' => 2.0, 'b' => 2.0], ['a' => 4, 'b' => 2]))->toBe(3.0);
});
