<?php

declare(strict_types=1);

use App\Support\OncePerHour;
use Illuminate\Support\Facades\Cache;

/**
 * «Una volta in quest'ora, al primo giro utile».
 *
 * `->hourly()` significa «al minuto zero»: basta che il cron slitti di un
 * minuto e il comando salta l'ora intera. Su questo server, in sei ore, sono
 * andati persi i giri delle 12:00 e delle 16:00 — su una macchina condivisa il
 * minuto esatto non è garantito a nessuno.
 *
 * La condizione qui verificata è quella che dà tolleranza: il comando si
 * presenta ogni cinque minuti e passa **una volta sola** per ora.
 */
beforeEach(function (): void {
    Cache::flush();
});

it('lascia passare il primo giro dell ora e ferma i successivi', function (): void {
    $condizione = OncePerHour::for('prova:comando');

    expect($condizione())->toBeTrue('il primo giro dell\'ora deve passare')
        ->and($condizione())->toBeFalse('il secondo giro della stessa ora no')
        ->and($condizione())->toBeFalse();
});

it('riparte all ora successiva', function (): void {
    $condizione = OncePerHour::for('prova:comando');

    expect($condizione())->toBeTrue();

    $this->travel(1)->hours();

    expect($condizione())->toBeTrue('un ora nuova è un giro nuovo');
});

it('recupera il giro perso quando il minuto zero salta', function (): void {
    /*
     * Il caso reale: alle 12:00 il cron non parte. Al primo giro utile — le
     * 12:05 — il comando deve partire lo stesso, non aspettare le 13:00.
     */
    $this->travelTo(now()->startOfHour()->addMinutes(5));

    expect(OncePerHour::for('import:run')())->toBeTrue();
});

it('tiene separati comandi diversi', function (): void {
    expect(OncePerHour::for('import:run')())->toBeTrue()
        ->and(OncePerHour::for('notifications:plan')())->toBeTrue(
            'due comandi non devono contendersi lo stesso posto nell\'ora'
        );
});
