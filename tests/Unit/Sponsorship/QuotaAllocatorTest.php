<?php

declare(strict_types=1);

use App\Services\Sponsorship\Delivery\QuotaAllocator;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
 * Come si divide lo spazio: fasce di priorità, quote delle unità a
 * visualizzazioni con riduzione proporzionale, unità a periodo per peso,
 * riserva, moltiplicatori di chi guarda ed estrazione.
 *
 * In fondo le proprietà che devono valere sempre, provate su 500 ingressi
 * generati con un seme.
 */

/**
 * @param  array{key: string, priority?: int, weight?: int|float, quota?: float|null}  ...$units
 * @return array{shares: array<string, float>, reserve: float, at_risk: list<string>}
 */
function p1Allocate(array ...$units): array
{
    return (new QuotaAllocator)->allocate($units);
}

it('gives the whole space to a single period unit', function (): void {
    $allocation = p1Allocate(['key' => 'g:1', 'priority' => 0, 'weight' => 1]);

    expect($allocation['shares'])->toBe(['g:1' => 1.0])
        ->and($allocation['reserve'])->toBe(0.0)
        ->and($allocation['at_risk'])->toBe([]);
});

it('splits period units by weight', function (): void {
    $allocation = p1Allocate(['key' => 'c', 'weight' => 2], ['key' => 'd', 'weight' => 1]);

    expect($allocation['shares']['c'])->toEqualWithDelta(2 / 3, 1e-12)
        ->and($allocation['shares']['d'])->toEqualWithDelta(1 / 3, 1e-12)
        ->and($allocation['reserve'])->toBe(0.0);
});

it('counts a weight below one as one', function (): void {
    $allocation = p1Allocate(['key' => 'a', 'weight' => 0], ['key' => 'b', 'weight' => -4], ['key' => 'c']);

    expect($allocation['shares'])->toBe(['a' => 1 / 3, 'b' => 1 / 3, 'c' => 1 / 3]);
});

it('leaves to the reserve what a lone goal unit does not ask', function (): void {
    $allocation = p1Allocate(['key' => 'a', 'quota' => 0.5]);

    expect($allocation['shares'])->toBe(['a' => 0.5])
        ->and($allocation['reserve'])->toBe(0.5);
});

it('gives period units what goal units leave in the band', function (): void {
    // L'esempio completo del documento: A 0,50 ; C e D a periodo con pesi 2 e 1.
    $allocation = p1Allocate(
        ['key' => 'a', 'quota' => 0.5],
        ['key' => 'c', 'weight' => 2],
        ['key' => 'd', 'weight' => 1],
    );

    expect($allocation['shares']['a'])->toBe(0.5)
        ->and($allocation['shares']['c'])->toEqualWithDelta(1 / 3, 1e-12)
        ->and($allocation['shares']['d'])->toEqualWithDelta(1 / 6, 1e-12)
        ->and($allocation['reserve'])->toBe(0.0);
});

it('lets a higher band with period units take everything', function (): void {
    $allocation = p1Allocate(
        ['key' => 'low', 'priority' => 0, 'weight' => 100],
        ['key' => 'high', 'priority' => 10, 'weight' => 1],
        ['key' => 'low-goal', 'priority' => 0, 'quota' => 0.5],
    );

    expect($allocation['shares'])->toBe(['high' => 1.0, 'low' => 0.0, 'low-goal' => 0.0])
        ->and($allocation['reserve'])->toBe(0.0);
});

it('passes down what a higher band does not use', function (): void {
    $allocation = p1Allocate(
        ['key' => 'top', 'priority' => 50, 'quota' => 0.3],
        ['key' => 'base', 'priority' => 0, 'weight' => 1],
    );

    expect($allocation['shares']['top'])->toBe(0.3)
        ->and($allocation['shares']['base'])->toEqualWithDelta(0.7, 1e-12)
        ->and($allocation['reserve'])->toBe(0.0);
});

it('orders the shares from the highest band', function (): void {
    $allocation = p1Allocate(
        ['key' => 'z', 'priority' => 1, 'quota' => 0.1],
        ['key' => 'y', 'priority' => 1000, 'quota' => 0.1],
        ['key' => 'x', 'priority' => 5, 'quota' => 0.1],
        ['key' => 'w', 'priority' => 1000, 'quota' => 0.1],
    );

    expect(array_keys($allocation['shares']))->toBe(['y', 'w', 'x', 'z'])
        ->and($allocation['reserve'])->toEqualWithDelta(0.6, 1e-12);
});

it('puts a unit without priority in band zero', function (): void {
    $allocation = p1Allocate(['key' => 'a', 'weight' => 1], ['key' => 'b', 'priority' => 0, 'weight' => 1]);

    expect($allocation['shares'])->toBe(['a' => 0.5, 'b' => 0.5]);
});

it('does not mark a quota exactly at the cap', function (): void {
    $allocation = p1Allocate(['key' => 'a', 'quota' => 0.8]);

    expect($allocation['shares'])->toBe(['a' => 0.8])
        ->and($allocation['at_risk'])->toBe([]);
});

it('lists a unit at risk once even when both reasons apply', function (): void {
    $allocation = p1Allocate(['key' => 'a', 'quota' => 2.0], ['key' => 'b', 'quota' => 0.7]);

    expect($allocation['at_risk'])->toBe(['a', 'b']);
});

it('scales down an oversold band and marks it at risk', function (): void {
    $allocation = p1Allocate(
        ['key' => 'a', 'priority' => 10, 'quota' => 0.6],
        ['key' => 'b', 'priority' => 10, 'quota' => 0.6],
        ['key' => 'c', 'priority' => 0, 'weight' => 1],
    );

    expect($allocation['shares'])->toBe(['a' => 0.5, 'b' => 0.5, 'c' => 0.0])
        ->and($allocation['at_risk'])->toBe(['a', 'b'])
        ->and($allocation['reserve'])->toBe(0.0);
});

it('scales a lower band to the space the higher band left', function (): void {
    $allocation = p1Allocate(
        ['key' => 'top', 'priority' => 2, 'quota' => 0.7],
        ['key' => 'low', 'priority' => 1, 'quota' => 0.5],
    );

    expect($allocation['shares']['top'])->toBe(0.7)
        ->and($allocation['shares']['low'])->toEqualWithDelta(0.3, 1e-12)
        ->and($allocation['at_risk'])->toBe(['low'])
        ->and($allocation['reserve'])->toBe(0.0);
});

it('does not mark a band that asks exactly the space left', function (): void {
    $allocation = p1Allocate(['key' => 'a', 'quota' => 0.5], ['key' => 'b', 'quota' => 0.5]);

    expect($allocation['shares'])->toBe(['a' => 0.5, 'b' => 0.5])
        ->and($allocation['at_risk'])->toBe([])
        ->and($allocation['reserve'])->toBe(0.0);
});

it('caps a single quota at the maximum and marks it', function (): void {
    $allocation = p1Allocate(['key' => 'a', 'quota' => 1.54], ['key' => 'b', 'quota' => 0.8]);

    expect($allocation['shares']['a'])->toBe(0.5)
        ->and($allocation['shares']['b'])->toBe(0.5)
        ->and($allocation['at_risk'])->toBe(['a', 'b']);

    $alone = p1Allocate(['key' => 'a', 'quota' => INF]);

    expect($alone['shares'])->toBe(['a' => 0.8])
        ->and($alone['reserve'])->toEqualWithDelta(0.2, 1e-12)
        ->and($alone['at_risk'])->toBe(['a']);
});

it('treats a broken quota as zero', function (): void {
    $allocation = p1Allocate(['key' => 'a', 'quota' => NAN], ['key' => 'b', 'quota' => -0.4], ['key' => 'c', 'quota' => 0.0]);

    expect($allocation['shares'])->toBe(['a' => 0.0, 'b' => 0.0, 'c' => 0.0])
        ->and($allocation['reserve'])->toBe(1.0)
        ->and($allocation['at_risk'])->toBe([]);
});

it('uses its own cap', function (): void {
    $allocation = (new QuotaAllocator(0.5))->allocate([['key' => 'a', 'quota' => 0.6]]);

    expect($allocation['shares'])->toBe(['a' => 0.5])
        ->and($allocation['at_risk'])->toBe(['a'])
        ->and((new QuotaAllocator(3.0))->allocate([['key' => 'a', 'quota' => 2.0]])['shares'])->toBe(['a' => 1.0])
        ->and((new QuotaAllocator(-1.0))->allocate([['key' => 'a', 'quota' => 0.3]])['shares'])->toBe(['a' => 0.0]);
});

it('returns everything to the reserve when nothing can be shown', function (): void {
    $allocation = (new QuotaAllocator)->allocate([]);

    expect($allocation)->toBe(['shares' => [], 'reserve' => 1.0, 'at_risk' => []]);
});

it('refuses a unit named like the reserve', function (): void {
    p1Allocate(['key' => QuotaAllocator::RESERVE]);
})->throws(InvalidArgumentException::class);

it('moves the space toward the units a signed-in person follows', function (): void {
    // A 0,50 ; C 0,333 ; D 0,167 e la persona segue il locale di D (a = 4).
    $allocation = p1Allocate(['key' => 'a', 'quota' => 0.5], ['key' => 'c', 'weight' => 2], ['key' => 'd', 'weight' => 1]);

    $probabilities = QuotaAllocator::modulate($allocation['shares'], $allocation['reserve'], ['d' => 4]);

    expect(round($probabilities['a'], 2))->toBe(0.33)
        ->and(round($probabilities['c'], 2))->toBe(0.22)
        ->and(round($probabilities['d'], 2))->toBe(0.44)
        ->and($probabilities[QuotaAllocator::RESERVE])->toBe(0.0)
        ->and(array_sum($probabilities))->toEqualWithDelta(1.0, 1e-12);
});

it('lets the reserve compete with multiplier one', function (): void {
    $probabilities = QuotaAllocator::modulate(['a' => 0.5], 0.5, ['a' => 3]);

    expect($probabilities)->toBe(['a' => 0.75, QuotaAllocator::RESERVE => 0.25]);
});

it('leaves the base shares alone for an anonymous visitor', function (): void {
    expect(QuotaAllocator::modulate(['a' => 0.5, 'b' => 0.25], 0.25))
        ->toBe(['a' => 0.5, 'b' => 0.25, QuotaAllocator::RESERVE => 0.25]);
});

it('takes a unit out of the draw with a zero, negative or broken multiplier', function (float $multiplier): void {
    $probabilities = QuotaAllocator::modulate(['a' => 0.5, 'b' => 0.5], 0.0, ['a' => $multiplier]);

    expect($probabilities)->toBe(['a' => 0.0, 'b' => 1.0, QuotaAllocator::RESERVE => 0.0]);
})->with([0.0, -2.0, INF, NAN]);

it('gives everything to the reserve when no unit is left', function (): void {
    expect(QuotaAllocator::modulate(['a' => 0.5], 0.0, ['a' => 0]))->toBe(['a' => 0.0, QuotaAllocator::RESERVE => 1.0])
        ->and(QuotaAllocator::modulate([], 0.0))->toBe([QuotaAllocator::RESERVE => 1.0])
        ->and(QuotaAllocator::modulate(['a' => -1.0], -1.0))->toBe(['a' => 0.0, QuotaAllocator::RESERVE => 1.0]);
});

it('picks the first key whose running total passes the number', function (): void {
    $probabilities = ['a' => 0.5, 'b' => 0.25, 'c' => 0.25];

    expect(QuotaAllocator::pick($probabilities, 0.0))->toBe('a')
        ->and(QuotaAllocator::pick($probabilities, 0.4999))->toBe('a')
        ->and(QuotaAllocator::pick($probabilities, 0.5))->toBe('b')
        ->and(QuotaAllocator::pick($probabilities, 0.7499))->toBe('b')
        ->and(QuotaAllocator::pick($probabilities, 0.75))->toBe('c')
        ->and(QuotaAllocator::pick($probabilities, 0.9999999))->toBe('c');
});

it('never picks a key without probability', function (): void {
    $probabilities = ['a' => 0.0, 'b' => 0.6, 'c' => 0.0, QuotaAllocator::RESERVE => 0.4, 'z' => 0.0];

    expect(QuotaAllocator::pick($probabilities, 0.0))->toBe('b')
        ->and(QuotaAllocator::pick($probabilities, 0.7))->toBe(QuotaAllocator::RESERVE)
        // Oltre la somma per arrotondamento: l'ultima chiave che può uscire.
        ->and(QuotaAllocator::pick($probabilities, 1.0))->toBe(QuotaAllocator::RESERVE)
        ->and(QuotaAllocator::pick(['a' => 0.0, 'b' => -1.0], 0.3))->toBeNull()
        ->and(QuotaAllocator::pick([], 0.3))->toBeNull();
});

it('returns string keys even for numeric unit keys', function (): void {
    expect(QuotaAllocator::pick([12 => 1.0], 0.5))->toBe('12')
        ->and(QuotaAllocator::pick([12 => 0.5], 0.9))->toBe('12');
});

/*
 * Proprietà valide sempre, su 500 ingressi generati con seme: le probabilità
 * sommano a 1, nessuna è negativa, una fascia più bassa non riceve niente
 * finché quella sopra chiede tutto, nessuna quota supera 0,8.
 */

/**
 * @return list<list<array{key: string, priority: int, weight: int, quota: float|null}>>
 */
function p1RandomUnitSets(int $count, int $seed): array
{
    $random = new Randomizer(new Xoshiro256StarStar($seed));
    $sets = [];

    for ($set = 0; $set < $count; $set++) {
        $units = [];
        $size = $random->getInt(0, 9);

        for ($i = 0; $i < $size; $i++) {
            $goal = $random->nextFloat() < 0.5;
            $units[] = [
                'key' => 'u'.$i,
                'priority' => [0, 0, 5, 10, 1000][$random->getInt(0, 4)],
                'weight' => $random->getInt(-1, 100),
                'quota' => $goal ? [0.0, 0.05, 0.3, 0.5, 0.8, 0.95, 1.6, INF][$random->getInt(0, 7)] * $random->nextFloat() * 2 : null,
            ];
        }

        $sets[] = $units;
    }

    return $sets;
}

it('keeps the probabilities a distribution on 500 seeded inputs', function (): void {
    $random = new Randomizer(new Xoshiro256StarStar(500));

    foreach (p1RandomUnitSets(500, 20261119) as $units) {
        $allocation = (new QuotaAllocator)->allocate($units);
        $multipliers = [];

        foreach ($units as $unit) {
            $multipliers[$unit['key']] = [0.0, 1.0, 1.5, 2.0, 3.0, 4.0][$random->getInt(0, 5)];
        }

        $base = $allocation['shares'] + [QuotaAllocator::RESERVE => $allocation['reserve']];
        $modulated = QuotaAllocator::modulate($allocation['shares'], $allocation['reserve'], $multipliers);

        expect(array_sum($base))->toEqualWithDelta(1.0, 1e-9)
            ->and(array_sum($modulated))->toEqualWithDelta(1.0, 1e-9)
            ->and(min($base))->toBeGreaterThanOrEqual(0.0)
            ->and(min($modulated))->toBeGreaterThanOrEqual(0.0)
            ->and(count($allocation['shares']))->toBe(count($units));
    }
});

it('never gives a goal unit more than the cap on 500 seeded inputs', function (): void {
    foreach (p1RandomUnitSets(500, 7) as $units) {
        $allocation = (new QuotaAllocator)->allocate($units);

        foreach ($units as $unit) {
            if ($unit['quota'] !== null) {
                expect($allocation['shares'][$unit['key']])->toBeLessThanOrEqual(0.8);
            }

            if ($unit['quota'] !== null && $unit['quota'] > 0.8) {
                expect($allocation['at_risk'])->toContain($unit['key']);
            }
        }
    }
});

it('gives a lower band nothing while a higher band asks for everything on 500 seeded inputs', function (): void {
    foreach (p1RandomUnitSets(500, 42) as $units) {
        $allocation = (new QuotaAllocator)->allocate($units);
        $priorities = array_unique(array_column($units, 'priority'));
        rsort($priorities);
        $space = 1.0;

        foreach ($priorities as $priority) {
            $band = array_filter($units, fn (array $unit): bool => $unit['priority'] === $priority);
            $taken = array_sum(array_map(fn (array $unit): float => $allocation['shares'][$unit['key']], $band));

            if ($space <= 1e-12) {
                expect($taken)->toBe(0.0);
            }

            expect($taken)->toBeLessThanOrEqual($space + 1e-12);

            $hasPeriod = array_filter($band, fn (array $unit): bool => $unit['quota'] === null) !== [];
            $space = $hasPeriod ? 0.0 : $space - $taken;
        }

        expect($allocation['reserve'])->toEqualWithDelta(max(0.0, $space), 1e-9);
    }
});
