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

it('conta come tornato chi è tornato entro sette giorni dalla propria prima volta', function (): void {
    $tornata = User::factory()->create();
    $sparita = User::factory()->create();
    $tardiva = User::factory()->create();

    // Tutte e tre arrivano nella settimana osservata: dieci giorni fa.
    Carbon::setTestNow(CarbonImmutable::now()->subDays(10));
    foreach ([$tornata, $sparita, $tardiva] as $person) {
        app(SaveOccurrences::class)->one($person, $this->occurrence);
    }

    $seconda = occurrenceAtLocal($this->city, $this->category, '2026-10-11 21:00');

    // Una torna dopo tre giorni: dentro la propria finestra.
    Carbon::setTestNow(CarbonImmutable::now()->addDays(3));
    app(SaveOccurrences::class)->many($tornata, $this->city, [$seconda->getKey()]);

    /* L'altra torna dopo nove: fuori dalla propria finestra, anche se cade
       nella settimana «recente» del calendario. È il caso che il conteggio a
       due finestre fisse sbagliava contandola come tornata. */
    Carbon::setTestNow(CarbonImmutable::now()->addDays(6));
    app(SaveOccurrences::class)->many($tardiva, $this->city, [$seconda->getKey()]);

    Carbon::setTestNow(CarbonImmutable::now()->addDays(1));
    $summary = app(ReturnMetrics::class)->summary($this->city);

    expect($summary['returning']['base'])->toBe(3)
        ->and($summary['returning']['returned'])->toBe(1)
        ->and($summary['returning']['rate'])->toBe(33.3);
});

it('non divide per zero quando non c è ancora nessuno', function (): void {
    $summary = app(ReturnMetrics::class)->summary($this->city);

    expect($summary['returning']['rate'])->toBe(0.0)
        ->and($summary['attendance'])->toBeNull()
        ->and($summary['booked'])->toBe(0);
});

it('non mescola le città', function (): void {
    $altra = testCity(['name' => 'Vicenza', 'slug' => 'vicenza']);
    $person = User::factory()->create();

    Carbon::setTestNow(CarbonImmutable::now()->subDays(10));
    app(SaveOccurrences::class)->one($person, $this->occurrence);

    Carbon::setTestNow(CarbonImmutable::now()->addDays(10));

    expect(app(ReturnMetrics::class)->summary($this->city)['returning']['base'])->toBe(1)
        ->and(app(ReturnMetrics::class)->summary($altra)['returning']['base'])->toBe(0);
});
