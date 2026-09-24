<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Models\User;
use App\Services\Analytics\ReturnMetrics;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * I numeri di ritorno.
 *
 * Il rischio qui non è il calcolo, è la definizione: «tornato» deve voler dire
 * una cosa sola e verificabile, altrimenti il numero diventa una promessa. Un
 * account che ha salvato una data due settimane fa e non ha più fatto niente
 * non è tornato, per quanto abbia visitato il sito.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-20 12:00');
    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-10-10 21:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('conta come tornato solo chi ha agito in entrambe le finestre', function (): void {
    $returning = User::factory()->create();
    $gone = User::factory()->create();

    Carbon::setTestNow(CarbonImmutable::now()->subDays(10));
    app(SaveOccurrences::class)->one($returning, $this->occurrence);
    app(SaveOccurrences::class)->one($gone, $this->occurrence);

    Carbon::setTestNow(CarbonImmutable::now()->addDays(8));
    app(SaveOccurrences::class)->many($returning, $this->city, [
        occurrenceAtLocal($this->city, $this->category, '2026-10-11 21:00')->getKey(),
    ]);

    Carbon::setTestNow(CarbonImmutable::now()->addDays(2));
    $summary = app(ReturnMetrics::class)->summary();

    expect($summary['returning']['base'])->toBe(2)
        ->and($summary['returning']['returned'])->toBe(1)
        ->and($summary['returning']['rate'])->toBe(50.0);
});

it('non divide per zero quando non c è ancora nessuno', function (): void {
    $summary = app(ReturnMetrics::class)->summary();

    expect($summary['returning']['rate'])->toBe(0.0)
        ->and($summary['attendance'])->toBeNull()
        ->and($summary['booked'])->toBe(0);
});
