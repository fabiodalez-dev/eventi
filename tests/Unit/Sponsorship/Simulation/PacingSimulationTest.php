<?php

declare(strict_types=1);

use App\Services\Sponsorship\Delivery\Pacer;
use App\Services\Sponsorship\Delivery\QuotaAllocator;
use App\Services\Sponsorship\Delivery\TrafficForecast;
use Carbon\CarbonImmutable;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
 * Il pacing su dieci giorni, con le classi vere (capitolo 7 del piano,
 * simulazioni 2, 3 e 4). Senza database e senza applicazione.
 *
 * È lo scenario di `03-simulazione.php`, rifatto con `TrafficForecast`,
 * `Pacer` e `QuotaAllocator` al posto delle formule scritte a mano, e con lo
 * stesso generatore consumato nello stesso ordine: per questo con il seme 7 i
 * numeri escono identici a quelli del documento.
 *
 * - 600 visite al giorno di base, con la curva e i giorni della simulazione;
 *   il traffico vero si scosta ogni giorno a caso fino al ±25% dalla
 *   previsione e le visite arrivano come un processo di Poisson.
 * - A: 3.000 visualizzazioni in 10 giorni. B: 1.200 fra il giorno 3 e il 8.
 *   C e D a periodo con pesi 2 e 1.
 * - «A ogni vista»: una quota al minuto e un'estrazione per visita (il piano
 *   al minuto). «A caselle»: una scelta ogni 30 minuti che vale per tutte le
 *   visite della casella (l'apertura della home in cache).
 *
 * I 200 semi del documento sono nel gruppo `simulazione` e si lanciano a parte
 * con `composer test:simulazione`: fuori da quel comando la prova non esiste
 * (non è saltata, non c'è).
 */

/** @var list<float> la forma di un giorno della simulazione del documento */
const P1_SIMULATION_CURVE = [1.5, 0.8, 0.4, 0.3, 0.3, 0.5, 1.2, 2.5, 4, 4.5, 4.5, 5, 6.5, 6.5, 5, 4.5, 5, 6, 7.5, 8.5, 8.5, 7, 5, 3];

/** @var list<float> i giorni, da lunedì a domenica */
const P1_SIMULATION_DAYS = [0.9, 0.9, 0.95, 1.0, 1.25, 1.3, 1.0];

/** La previsione della simulazione: 600 visite al giorno di base, come `forecastHour()`. */
function p1SimulationForecast(): TrafficForecast
{
    $sum = array_sum(P1_SIMULATION_CURVE);
    $curve = array_map(fn (float|int $share): float => $share / $sum, P1_SIMULATION_CURVE);
    $profile = [];

    for ($hour = 0; $hour < TrafficForecast::HOURS_PER_WEEK; $hour++) {
        $profile[] = 600.0 * P1_SIMULATION_DAYS[intdiv($hour, 24) % 7] * $curve[$hour % 24];
    }

    return TrafficForecast::fromProfile($profile);
}

function p1Poisson(Randomizer $random, float $mean): int
{
    $limit = exp(-$mean);
    $count = 0;
    $product = 1.0;

    do {
        $count++;
        $product *= $random->nextFloat();
    } while ($product > $limit);

    return $count - 1;
}

/**
 * Dieci giorni di consegna.
 *
 * @return array{a: int, b: int, c: int, d: int, a_per_day: list<int>, a_quota_at_20: list<float>}
 */
function p1SimulatePacing(string $mode, int $seed): array
{
    $random = new Randomizer(new Xoshiro256StarStar($seed));
    $forecast = p1SimulationForecast();
    $pacer = new Pacer;
    $allocator = new QuotaAllocator;

    // Un lunedì a mezzanotte, in UTC: l'ora h della simulazione è l'ora h della settimana tipo.
    $start = CarbonImmutable::parse('2026-11-23 00:00', 'UTC');
    $hours = 240;
    $goals = [
        'a' => ['goal' => 3000, 'from' => 0, 'to' => 240, 'end' => $start->addHours(240)],
        'b' => ['goal' => 1200, 'from' => 72, 'to' => 192, 'end' => $start->addHours(192)],
    ];
    $delivered = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0];
    $aPerDay = array_fill(0, 10, 0);
    $aQuotaAt20 = [];
    $slots = $mode === 'every-view' ? 60 : 2;
    $dayFactor = 1.0;

    for ($hour = 0; $hour < $hours; $hour++) {
        if ($hour % 24 === 0) {
            $dayFactor = 0.75 + 0.5 * $random->nextFloat();
        }

        $arrivals = p1Poisson($random, $forecast->hourly($hour) * $dayFactor);
        $perSlot = array_fill(0, $slots, 0);

        for ($i = 0; $i < $arrivals; $i++) {
            $perSlot[$random->getInt(0, $slots - 1)]++;
        }

        for ($slot = 0; $slot < $slots; $slot++) {
            $now = $start->addSeconds($hour * 3600 + intdiv($slot * 3600, $slots));
            $units = [];

            foreach ($goals as $key => $unit) {
                if ($hour < $unit['from'] || $hour >= $unit['to'] || $delivered[$key] >= $unit['goal']) {
                    continue;
                }

                $units[] = ['key' => $key, 'quota' => $pacer->requiredUntil($unit['goal'], $delivered[$key], $forecast, $now, $unit['end'])];
            }

            $units[] = ['key' => 'c', 'weight' => 2];
            $units[] = ['key' => 'd', 'weight' => 1];
            $allocation = $allocator->allocate($units);
            $probabilities = $allocation['shares'] + [QuotaAllocator::RESERVE => $allocation['reserve']];

            if ($slot === 0 && $hour % 24 === 20) {
                $aQuotaAt20[] = $allocation['shares']['a'] ?? 0.0;
            }

            if ($mode === 'every-view') {
                for ($i = 0; $i < $perSlot[$slot]; $i++) {
                    $key = (string) QuotaAllocator::pick($probabilities, $random->nextFloat());

                    // Dentro il minuto un'unità può arrivare all'obiettivo: da lì non è più visibile.
                    if (isset($goals[$key]) && $delivered[$key] >= $goals[$key]['goal']) {
                        $key = 'c';
                    }

                    $delivered[$key]++;
                    $aPerDay[intdiv($hour, 24)] += $key === 'a' ? 1 : 0;
                }

                continue;
            }

            $key = (string) QuotaAllocator::pick($probabilities, $random->nextFloat());
            $delivered[$key] += $perSlot[$slot];
            $aPerDay[intdiv($hour, 24)] += $key === 'a' ? $perSlot[$slot] : 0;
        }
    }

    return $delivered + ['a_per_day' => $aPerDay, 'a_quota_at_20' => $aQuotaAt20];
}

it('closes A at 3000 of 3000 with seed 7, following the traffic', function (): void {
    $result = p1SimulatePacing('every-view', 7);

    // I numeri del documento: A 3.000/3.000, B 1.172/1.200, C 1.132, D 568.
    expect($result['a'])->toBe(3000)
        ->and($result['b'])->toBe(1172)
        ->and($result['c'])->toBe(1132)
        ->and($result['d'])->toBe(568)
        ->and($result['c'] / $result['d'])->toBeGreaterThanOrEqual(1.8)->toBeLessThanOrEqual(2.2)
        // A segue il traffico: il sesto giorno è il sabato.
        ->and($result['a_per_day'])->toBe([279, 269, 268, 342, 266, 510, 231, 224, 295, 316])
        ->and(max($result['a_per_day']))->toBe($result['a_per_day'][5]);

    // La quota chiesta da A alle 20 resta fra 0,48 e 0,55 (al centesimo, come
    // la stampa il documento): niente scatti.
    expect($result['a_quota_at_20'])->toHaveCount(10);

    foreach ($result['a_quota_at_20'] as $quota) {
        expect(round($quota, 2))->toBeGreaterThanOrEqual(0.48)->toBeLessThanOrEqual(0.55);
    }
})->group('simulazione-breve');

it('keeps A between 2940 and 3000 on 20 seeds', function (): void {
    $c = 0;
    $d = 0;

    foreach (range(1, 20) as $seed) {
        $result = p1SimulatePacing('every-view', $seed);
        $c += $result['c'];
        $d += $result['d'];

        expect($result['a'])->toBeGreaterThanOrEqual(2940)->toBeLessThanOrEqual(3000)
            ->and($result['b'])->toBeLessThanOrEqual(1200);
    }

    // Il rapporto fra le due unità a periodo, su una prova sola, oscilla fra
    // 1,79 e 2,22 (circa 570 visite a D): sulle venti insieme sta vicino a 2.
    expect($c / $d)->toBeGreaterThanOrEqual(1.9)->toBeLessThanOrEqual(2.1);
})->group('simulazione-breve');

it('never goes more than 1% over the goal with 30-minute slots on 20 seeds', function (): void {
    foreach (range(1, 20) as $seed) {
        $result = p1SimulatePacing('slots', $seed);

        // L'obiettivo di A (3.000) mai oltre l'1%. B ha un obiettivo più
        // piccolo (1.200), quindi una casella di troppo pesa di più: sui 20
        // semi arriva a 1.215 (+1,25%), e il tetto della prova è il 2%.
        expect($result['a'])->toBeLessThanOrEqual(3030)
            ->and($result['b'])->toBeLessThanOrEqual(1224);
    }

    // Con il seme 7 il documento riporta 2.995.
    expect(p1SimulatePacing('slots', 7)['a'])->toBe(2995);
})->group('simulazione-breve');

if (getenv('SPONSORSHIP_SIMULATION') === '1') {
    it('matches the figures of the plan on 200 seeds', function (): void {
        $everyView = ['a' => [], 'b' => []];
        $slots = [];

        foreach (range(1, 200) as $seed) {
            $result = p1SimulatePacing('every-view', $seed);
            $everyView['a'][] = $result['a'];
            $everyView['b'][] = $result['b'];
            $slots[] = p1SimulatePacing('slots', $seed)['a'];
        }

        sort($everyView['a']);
        sort($everyView['b']);
        sort($slots);

        // Documento: A minimo 2.944, mediana e massimo 3.000; B minimo 1.143,
        // mediana 1.200; a caselle massimo 3.023.
        expect($everyView['a'][0])->toBe(2944)
            ->and($everyView['a'][100])->toBe(3000)
            ->and($everyView['a'][199])->toBe(3000)
            ->and($everyView['b'][0])->toBe(1143)
            ->and($everyView['b'][100])->toBe(1200)
            ->and($slots[199])->toBe(3023)
            ->and($slots[199])->toBeLessThanOrEqual(3030);
    })->group('simulazione');
}
