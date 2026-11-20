<?php

declare(strict_types=1);

use App\Enums\SponsorshipPacing;
use App\Services\Sponsorship\Delivery\Pacer;
use App\Services\Sponsorship\Delivery\TrafficForecast;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/*
 * Il pacing delle unità a visualizzazioni: i tre casi a mano del piano
 * (0,50 ; 0,56 ; 0,8 con segnalazione) e i bordi (obiettivo raggiunto,
 * traffico zero, periodo finito, «il prima possibile»).
 *
 * Nessun database e nessuna applicazione: il tempo entra come parametro.
 */

/**
 * La curva di un giorno della simulazione del piano, scalata su `$daily`
 * visite in ogni giorno della settimana.
 *
 * @return list<float>
 */
function p1PacerDayCurveProfile(float $daily): array
{
    $curve = TrafficForecast::STARTING_HOURLY_CURVE;
    $sum = array_sum($curve);
    $profile = [];

    for ($day = 0; $day < 7; $day++) {
        foreach ($curve as $share) {
            $profile[] = $daily * $share / $sum;
        }
    }

    return $profile;
}

it('asks for half of the space at the start of the hand example', function (): void {
    // Obiettivo 3.000 in 10 giorni, previsione 600 viste al giorno.
    $start = CarbonImmutable::parse('2026-11-23 00:00', 'Europe/Rome');
    $forecast = TrafficForecast::flat(600);

    $pacer = new Pacer;

    expect($forecast->between($start, $start->addDays(10)))->toBe(6000.0)
        ->and($pacer->requiredUntil(3000, 0, $forecast, $start, $start->addDays(10)))->toBe(0.5)
        ->and($pacer->quotaUntil(3000, 0, $forecast, $start, $start->addDays(10)))->toBe(0.5)
        ->and($pacer->isAtRisk(0.5))->toBeFalse();
});

it('catches up without jumps after four slow days', function (): void {
    // Dopo 4 giorni ne ha consegnate 1.000 (attese 1.200): restano 3.600 previste.
    $start = CarbonImmutable::parse('2026-11-23 00:00', 'Europe/Rome');
    $forecast = TrafficForecast::flat(600);
    $pacer = new Pacer;

    $quota = $pacer->quotaUntil(3000, 1000, $forecast, $start->addDays(4), $start->addDays(10));

    expect($quota)->toEqualWithDelta(2000 / 3600, 1e-12)
        ->and(round($quota, 2))->toBe(0.56)
        ->and($pacer->isAtRisk($quota))->toBeFalse();
});

it('reaches the cap and raises the flag on the last evening', function (): void {
    // Ultimo giorno alle 19: con la curva di partenza restano circa 195 viste.
    $evening = CarbonImmutable::parse('2026-12-02 19:00', 'Europe/Rome');
    $end = $evening->setTime(24, 0);
    $forecast = TrafficForecast::fromProfile(p1PacerDayCurveProfile(600));
    $pacer = new Pacer;

    $remaining = $forecast->between($evening, $end);

    expect(round($remaining))->toBe(195.0);

    $short = $pacer->requiredUntil(3000, 2850, $forecast, $evening, $end);
    $far = $pacer->requiredUntil(3000, 2700, $forecast, $evening, $end);

    expect(round($short, 2))->toBe(0.77)
        ->and($pacer->isAtRisk($short))->toBeFalse()
        ->and(round($far, 2))->toBe(1.54)
        ->and($pacer->quotaUntil(3000, 2700, $forecast, $evening, $end))->toBe(0.8)
        ->and($pacer->isAtRisk($far))->toBeTrue();
});

it('asks for nothing once the goal is reached or exceeded', function (int $delivered): void {
    $pacer = new Pacer;

    expect($pacer->required(3000, $delivered, 1000))->toBe(0.0)
        ->and($pacer->quota(3000, $delivered, 1000))->toBe(0.0)
        ->and($pacer->required(3000, $delivered, 1000, SponsorshipPacing::Asap))->toBe(0.0);
})->with([3000, 3001, 9000]);

it('asks for nothing without a goal', function (): void {
    $pacer = new Pacer;

    expect($pacer->required(0, 0, 1000))->toBe(0.0)
        ->and($pacer->required(-5, 0, 1000))->toBe(0.0);
});

it('flags a unit when no traffic is left for what is missing', function (): void {
    $pacer = new Pacer;

    expect($pacer->required(3000, 10, 0.0))->toBe(INF)
        ->and($pacer->required(3000, 10, -4.0))->toBe(INF)
        ->and($pacer->quota(3000, 10, 0.0))->toBe(0.8)
        ->and($pacer->isAtRisk($pacer->required(3000, 10, 0.0)))->toBeTrue();
});

it('asks for nothing once the period is over', function (): void {
    $end = CarbonImmutable::parse('2026-12-03 00:00', 'Europe/Rome');
    $pacer = new Pacer;
    $forecast = TrafficForecast::flat(600);

    expect($pacer->requiredUntil(3000, 100, $forecast, $end, $end))->toBe(0.0)
        ->and($pacer->requiredUntil(3000, 100, $forecast, $end->addMinute(), $end))->toBe(0.0)
        ->and($pacer->quotaUntil(3000, 100, $forecast, $end->addDay(), $end))->toBe(0.0)
        // Un istante prima della fine il traffico che resta è quasi zero: si chiede tutto.
        ->and($pacer->quotaUntil(3000, 100, $forecast, $end->subSecond(), $end))->toBe(0.8);
});

it('asks for the cap until the goal with as-soon-as-possible pacing', function (): void {
    $pacer = new Pacer;

    expect($pacer->required(3000, 0, 1_000_000, SponsorshipPacing::Asap))->toBe(0.8)
        ->and($pacer->required(3000, 2999, 10, SponsorshipPacing::Asap))->toBe(0.8)
        ->and($pacer->required(3000, 2999, 0, SponsorshipPacing::Asap))->toBe(0.8)
        ->and($pacer->isAtRisk($pacer->required(3000, 0, 10, SponsorshipPacing::Asap)))->toBeFalse();
});

it('recomputes the quota when the unit is ahead', function (): void {
    $pacer = new Pacer;

    // Avanti di 500 a metà periodo: chiede meno della metà.
    expect($pacer->quota(3000, 2000, 3000))->toEqualWithDelta(1 / 3, 1e-12)
        ->and($pacer->quota(3000, 2990, 3000))->toEqualWithDelta(10 / 3000, 1e-12);
});

it('keeps the cap between zero and one', function (float $configured, float $expected): void {
    expect((new Pacer($configured))->quotaMax())->toBe($expected);
})->with([
    'default' => [0.8, 0.8],
    'lower' => [0.5, 0.5],
    'above one' => [1.7, 1.0],
    'negative' => [-0.2, 0.0],
]);

it('uses its own cap for quota and risk', function (): void {
    $pacer = new Pacer(0.5);

    expect($pacer->quota(3000, 0, 4000))->toBe(0.5)
        ->and($pacer->isAtRisk(0.6))->toBeTrue()
        ->and($pacer->isAtRisk(0.5))->toBeFalse()
        ->and($pacer->required(3000, 0, 4000, SponsorshipPacing::Asap))->toBe(0.5);
});

it('takes the earlier of the campaign end and the last covered date', function (): void {
    $endsAt = CarbonImmutable::parse('2026-11-30 23:59', 'Europe/Rome');
    $lastDate = CarbonImmutable::parse('2026-11-20 23:00', 'Europe/Rome');

    expect(Pacer::trueEnd($endsAt, $lastDate)->equalTo($lastDate))->toBeTrue()
        ->and(Pacer::trueEnd($lastDate, $endsAt)->equalTo($lastDate))->toBeTrue()
        ->and(Pacer::trueEnd($endsAt, null)->equalTo($endsAt))->toBeTrue()
        ->and(Pacer::trueEnd($endsAt, $endsAt)->equalTo($endsAt))->toBeTrue()
        ->and(Pacer::trueEnd(Carbon::parse('2026-11-30 10:00', 'UTC'), null))->toBeInstanceOf(CarbonImmutable::class);
});

it('spreads the goal over the true end, not over the empty days after it', function (): void {
    $now = CarbonImmutable::parse('2026-11-10 00:00', 'Europe/Rome');
    $forecast = TrafficForecast::flat(600);
    $pacer = new Pacer;

    // Abilitazione fino al 30, ultimo evento il 20: dieci giorni veri, non venti.
    $end = Pacer::trueEnd($now->setDate(2026, 11, 30), $now->setDate(2026, 11, 20));

    expect($pacer->quotaUntil(3000, 0, $forecast, $now, $end))->toBe(0.5);
});
