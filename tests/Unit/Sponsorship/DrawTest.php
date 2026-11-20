<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Providers\SponsorshipServiceProvider;
use App\Services\Sponsorship\Delivery\Draw;
use App\Services\Sponsorship\Delivery\HashDraw;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use App\Services\Sponsorship\Delivery\RandomDraw;
use App\Services\Sponsorship\Delivery\SeededDraw;
use App\Services\Sponsorship\Delivery\TrafficHistory;
use Carbon\CarbonImmutable;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\TestCase;

/*
 * Il caso come dipendenza: quello vero, quello a seme dei test, e quello a
 * hash dell'apertura della home, uguale per tutti dentro la casella.
 *
 * Il contenitore serve solo alle ultime prove (quale realizzazione si riceve
 * e quanto dura la casella): il resto non tocca niente fuori dalle classi.
 */
uses(TestCase::class);

/** @return list<float> */
function p0Fractions(Draw $draw, int $count): array
{
    $values = [];

    for ($i = 0; $i < $count; $i++) {
        $values[] = $draw->fraction();
    }

    return $values;
}

/**
 * La scelta della simulazione del piano: la prima quota cumulata che supera
 * il numero estratto.
 *
 * @param  array<string, float>  $quote
 */
function p0Pick(array $quote, float $u): string
{
    $cumulata = 0.0;

    foreach ($quote as $chiave => $quota) {
        $cumulata += $quota;

        if ($u < $cumulata) {
            return $chiave;
        }
    }

    return (string) array_key_last($quote);
}

it('repeats the same sequence for the same seed', function (): void {
    expect(p0Fractions(new SeededDraw(7), 50))->toBe(p0Fractions(new SeededDraw(7), 50))
        ->and(p0Fractions(new SeededDraw(7), 50))->not->toBe(p0Fractions(new SeededDraw(8), 50));
});

it('uses the same generator as the plan simulation', function (): void {
    $simulazione = new Randomizer(new Xoshiro256StarStar(20261119));
    $attesi = [];

    for ($i = 0; $i < 100; $i++) {
        $attesi[] = $simulazione->nextFloat();
    }

    expect(p0Fractions(new SeededDraw(20261119), 100))->toBe($attesi);
});

it('reproduces the 10,000 draws of the plan to the exact number', function (): void {
    $quote = ['A' => 0.5, 'B' => 0.25, 'C' => 1 / 6, 'D' => 1 / 12];
    $uscite = array_fill_keys(array_keys($quote), 0);
    $caso = new SeededDraw(20261119);

    for ($i = 0; $i < 10_000; $i++) {
        $uscite[p0Pick($quote, $caso->fraction())]++;
    }

    expect($uscite)->toBe(['A' => 5008, 'B' => 2468, 'C' => 1668, 'D' => 856]);
});

it('keeps every fraction between zero included and one excluded', function (Draw $draw): void {
    foreach (p0Fractions($draw, 5_000) as $valore) {
        expect($valore)->toBeGreaterThanOrEqual(0.0)->toBeLessThan(1.0);
    }
})->with([
    'random' => [fn (): Draw => new RandomDraw],
    'seeded' => [fn (): Draw => new SeededDraw(1)],
    'hash' => [fn (): Draw => new HashDraw(1, 'home_hero', 1)],
]);

it('draws different numbers with the real generator', function (): void {
    expect(array_unique(p0Fractions(new RandomDraw, 1_000)))->toHaveCount(1_000)
        ->and(p0Fractions(new RandomDraw, 20))->not->toBe(p0Fractions(new RandomDraw, 20));
});

it('gives the same number to everyone in the same slot', function (): void {
    expect((new HashDraw(3, 'home_hero', 980_000))->fraction())->toBe((new HashDraw(3, 'home_hero', 980_000))->fraction())
        ->and(p0Fractions(new HashDraw(3, 'home_hero', 980_000), 10))->toBe(p0Fractions(new HashDraw(3, 'home_hero', 980_000), 10));
});

it('moves on to a new number at every call inside the slot', function (): void {
    expect(array_unique(p0Fractions(new HashDraw(3, 'home_hero', 980_000), 10)))->toHaveCount(10);
});

it('changes the number with the city, the placement and the slot', function (): void {
    $base = (new HashDraw(3, 'home_hero', 980_000))->fraction();

    expect((new HashDraw(4, 'home_hero', 980_000))->fraction())->not->toBe($base)
        ->and((new HashDraw(3, 'home_card', 980_000))->fraction())->not->toBe($base)
        ->and((new HashDraw(3, 'home_hero', 980_001))->fraction())->not->toBe($base);
});

it('puts two instants of the same half hour in the same slot, and the next half hour in the next one', function (): void {
    $alle = fn (string $quando): CarbonImmutable => CarbonImmutable::parse($quando, 'Europe/Rome');

    $prima = HashDraw::forSlot(3, SponsorshipPlacement::HomeHero, $alle('2026-11-20 21:00:00'), 30);
    $stessa = HashDraw::forSlot(3, 'home_hero', $alle('2026-11-20 21:29:59'), 30);
    $dopo = HashDraw::forSlot(3, 'home_hero', $alle('2026-11-20 21:30:00'), 30);

    expect($stessa->slot())->toBe($prima->slot())
        ->and($stessa->fraction())->toBe($prima->fraction())
        ->and($dopo->slot())->toBe($prima->slot() + 1)
        ->and(HashDraw::slotIndex($alle('2026-11-20 21:00:00'), 60))->toBe(HashDraw::slotIndex($alle('2026-11-20 21:59:59'), 60));
});

it('refuses a slot shorter than one minute', function (): void {
    HashDraw::slotIndex(CarbonImmutable::now(), 0);
})->throws(InvalidArgumentException::class);

it('spreads its numbers evenly over 10,000 slots', function (): void {
    $caselle = array_fill(0, 10, 0);
    $somma = 0.0;

    for ($slot = 0; $slot < 10_000; $slot++) {
        $valore = (new HashDraw(1, 'home_hero', 1_000_000 + $slot))->fraction();
        $caselle[(int) floor($valore * 10)]++;
        $somma += $valore;
    }

    /* Chi quadro con 9 gradi di libertà: 27,88 è la soglia dello 0,1%. */
    $chiQuadro = array_sum(array_map(fn (int $n): float => ($n - 1000) ** 2 / 1000, $caselle));

    expect($chiQuadro)->toBeLessThan(27.88)
        ->and(min($caselle))->toBeGreaterThan(900)
        ->and(max($caselle))->toBeLessThan(1100)
        ->and($somma / 10_000)->toBeGreaterThan(0.49)->toBeLessThan(0.51);
});

it('keeps the hero slot as long as the page cache unless configured otherwise', function (): void {
    config(['sponsorship.hero_slot_minutes' => null, 'page_cache.ttl_minutes' => 45]);
    expect(HashDraw::slotMinutes())->toBe(45);

    config(['sponsorship.hero_slot_minutes' => 20]);
    expect(HashDraw::slotMinutes())->toBe(20);
});

it('follows the page cache of the real configuration', function (): void {
    expect(config('sponsorship.hero_slot_minutes'))->toBeNull()
        ->and(HashDraw::slotMinutes())->toBe((int) config('page_cache.ttl_minutes'))
        ->and(HashDraw::slotMinutes())->toBeGreaterThanOrEqual(30);
});

it('hands out the real generator from the container, with or without the provider', function (): void {
    expect(app(Draw::class))->toBeInstanceOf(RandomDraw::class);

    app()->register(SponsorshipServiceProvider::class, force: true);

    expect(app(Draw::class))->toBeInstanceOf(RandomDraw::class)
        ->and(app(ImpressionToken::class))->toBe(app(ImpressionToken::class))
        ->and(app(TrafficHistory::class))->toBe(app(TrafficHistory::class));
});

it('lets a test swap the generator for a seeded one', function (): void {
    app()->instance(Draw::class, new SeededDraw(7));

    expect(app(Draw::class)->fraction())->toBe((new SeededDraw(7))->fraction());
});
