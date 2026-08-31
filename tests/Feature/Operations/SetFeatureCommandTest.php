<?php

declare(strict_types=1);

use App\Models\City;
use App\Support\Features;
use Laravel\Pennant\Feature;

/**
 * Il comando che rende gli interruttori qualcosa che si può davvero girare.
 * Senza, spegnere l'import di una città in produzione richiederebbe
 * un'espressione PHP scritta a mano su una macchina viva.
 */
it('spegne e riaccende l\'import di una città', function (): void {
    $city = testCity();

    $this->artisan('feature:set', ['feature' => Features::CITY_IMPORT, '--city' => $city->slug, '--off' => true])
        ->assertSuccessful();

    expect(Features::importActiveFor($city->refresh()))->toBeFalse();

    $this->artisan('feature:set', ['feature' => Features::CITY_IMPORT, '--city' => $city->slug])
        ->assertSuccessful();

    expect(Features::importActiveFor($city->refresh()))->toBeTrue();
});

it('spegne la newsletter per tutto il sistema', function (): void {
    $this->artisan('feature:set', ['feature' => Features::NEWSLETTER, '--off' => true])->assertSuccessful();

    expect(Features::newsletterActive())->toBeFalse();
});

it('rifiuta un interruttore che non esiste', function (): void {
    $this->artisan('feature:set', ['feature' => 'import-di-tutto'])->assertFailed();
});

it('rifiuta di spegnere l\'import senza dire quale città', function (): void {
    $city = testCity();

    $this->artisan('feature:set', ['feature' => Features::CITY_IMPORT, '--off' => true])->assertFailed();

    expect(Features::importActiveFor($city))->toBeTrue();
});

/**
 * Un `--city` su un interruttore che non ne ha una non viene ignorato in
 * silenzio: chi lo ha scritto se ne andrebbe convinto di aver spento una città
 * sola, e avrebbe spento il sistema intero.
 */
it('rifiuta una città su un interruttore che non ne ha', function (): void {
    testCity();

    $this->artisan('feature:set', ['feature' => Features::NEWSLETTER, '--city' => 'padova', '--off' => true])
        ->assertFailed();

    expect(Features::newsletterActive())->toBeTrue();
});

it('rifiuta una città che non esiste', function (): void {
    City::query()->delete();

    $this->artisan('feature:set', ['feature' => Features::CITY_IMPORT, '--city' => 'verona', '--off' => true])
        ->assertFailed();
});

it('scrive la decisione in una riga sola, riscrivibile', function (): void {
    $city = testCity();

    $this->artisan('feature:set', ['feature' => Features::CITY_IMPORT, '--city' => $city->slug, '--off' => true]);
    $this->artisan('feature:set', ['feature' => Features::CITY_IMPORT, '--city' => $city->slug, '--off' => true]);

    expect(DB::table('features')->where('name', Features::CITY_IMPORT)->count())->toBe(1);

    Feature::purge();

    expect(DB::table('features')->count())->toBe(0);
});
