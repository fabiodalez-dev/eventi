<?php

declare(strict_types=1);

use App\Services\Sponsorship\Delivery\EventWeights;
use App\Services\Sponsorship\Delivery\QuotaAllocator;
use App\Services\Sponsorship\Delivery\SeededDraw;
use Carbon\CarbonImmutable;

/*
 * Simulazioni dell'estrazione, con seme e senza database (capitolo 7 del
 * piano, simulazioni 1 e 5).
 *
 * Con il seme i numeri sono ripetibili, quindi affermo quelli esatti; in più
 * la tolleranza statistica, così un cambio di generatore si distingue da un
 * errore di quota.
 */

it('draws 5008, 2468, 1668 and 856 out of 10,000 with seed 20261119', function (): void {
    // Quote 0,50 / 0,25 / 0,1667 / 0,0833: quattro unità a periodo con pesi 6, 3, 2, 1.
    $allocation = (new QuotaAllocator)->allocate([
        ['key' => 'a', 'weight' => 6],
        ['key' => 'b', 'weight' => 3],
        ['key' => 'c', 'weight' => 2],
        ['key' => 'd', 'weight' => 1],
    ]);

    expect($allocation['shares'])->toBe(['a' => 0.5, 'b' => 0.25, 'c' => 1 / 6, 'd' => 1 / 12])
        ->and($allocation['reserve'])->toBe(0.0);

    $probabilities = $allocation['shares'] + [QuotaAllocator::RESERVE => $allocation['reserve']];
    $draw = new SeededDraw(20261119);
    $counts = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0];

    for ($i = 0; $i < 10_000; $i++) {
        $counts[QuotaAllocator::pick($probabilities, $draw->fraction())]++;
    }

    expect($counts)->toBe(['a' => 5008, 'b' => 2468, 'c' => 1668, 'd' => 856]);

    foreach ($allocation['shares'] as $key => $share) {
        $sigma = sqrt(10_000 * $share * (1 - $share));

        expect(abs($counts[$key] - 10_000 * $share))->toBeLessThan(3 * $sigma);
    }
});

it('gives the same space to a unit with 40 events and a unit with one', function (): void {
    // Lo stesso acquisto: un locale con quaranta serate, un locale con una.
    $now = CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Rome');
    $allocation = (new QuotaAllocator)->allocate([['key' => 'g:40', 'weight' => 1], ['key' => 'g:1', 'weight' => 1]]);
    $weights = new EventWeights;

    $events = [
        'g:40' => $weights->shares(array_map(fn (int $day): CarbonImmutable => $now->addDays($day), range(0, 39)), $now),
        'g:1' => $weights->shares(['solo' => $now->addDays(12)], $now),
    ];

    $draw = new SeededDraw(20261119);
    $units = ['g:40' => 0, 'g:1' => 0];
    $shown = [];

    for ($i = 0; $i < 10_000; $i++) {
        // Due passi: prima l'unità, poi l'evento dentro l'unità.
        $unit = (string) QuotaAllocator::pick($allocation['shares'], $draw->fraction());
        $event = QuotaAllocator::pick($events[$unit], $draw->fraction());
        $units[$unit]++;
        $shown[$unit.'/'.$event] = true;
    }

    expect($units['g:40'] / 10_000)->toBeGreaterThanOrEqual(0.48)->toBeLessThanOrEqual(0.52)
        ->and($units['g:1'] / 10_000)->toBeGreaterThanOrEqual(0.48)->toBeLessThanOrEqual(0.52)
        // Dentro il locale grande escono anche le serate lontane, grazie al pavimento.
        ->and(count($shown))->toBe(41);

    // Una riga per evento sulla ruota, come oggi: il locale grande avrebbe 40 posti su 41.
    $rowsOnWheel = (new QuotaAllocator)->allocate(array_merge(
        array_map(fn (int $event): array => ['key' => 'row-'.$event, 'weight' => 1], range(1, 40)),
        [['key' => 'row-solo', 'weight' => 1]],
    ));

    expect($rowsOnWheel['shares']['row-solo'])->toBeLessThan(0.025);
});

it('moves space toward closer events inside the same unit only', function (): void {
    $now = CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Rome');
    $shares = (new EventWeights)->shares(['a' => $now->addDays(1), 'b' => $now->addDays(6), 'c' => $now->addDays(20)], $now);
    $draw = new SeededDraw(7);
    $counts = ['a' => 0, 'b' => 0, 'c' => 0];

    for ($i = 0; $i < 10_000; $i++) {
        $counts[QuotaAllocator::pick($shares, $draw->fraction())]++;
    }

    foreach ($shares as $key => $share) {
        $sigma = sqrt(10_000 * $share * (1 - $share));

        expect(abs($counts[$key] - 10_000 * $share))->toBeLessThan(3 * $sigma);
    }

    expect($counts['a'])->toBeGreaterThan($counts['b'])
        ->and($counts['b'])->toBeGreaterThan($counts['c']);
});
