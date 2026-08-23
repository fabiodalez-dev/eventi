<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Queries\EventOccurrenceQuery;

/**
 * §8.4 e scenari C, C2 e C3 di §18.
 *
 * ```
 * IN CORSO
 *   starts_at <= now  AND  effective_ends_at >= now
 *   AND status = scheduled
 *   AND category.supports_ongoing = true
 *   AND is_all_day = false
 * ```
 *
 * "Adesso" è l'adesso della città (§8.1): il tempo si fissa sempre su un
 * orario locale, mai su un istante del server.
 */
beforeEach(function (): void {
    $this->city = testCity();
});

it('mostra in corso un evento 18:00–20:00 quando sono le 19:00 — scenario C', function (): void {
    $occurrence = occurrenceAtLocal(
        $this->city,
        testCategory(),
        '2026-05-15 18:00',
        '2026-05-15 20:00',
    );

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->ongoing()->get()))
        ->toBe([(int) $occurrence->getKey()]);
});

it('non lo mostra più alle 20:01 — scenario C', function (): void {
    occurrenceAtLocal(
        $this->city,
        testCategory(),
        '2026-05-15 18:00',
        '2026-05-15 20:00',
    );

    freezeLocal($this->city, '2026-05-15 20:01');

    expect(EventOccurrenceQuery::for($this->city)->ongoing()->get())->toBeEmpty();
});

it('comprende gli istanti esatti di inizio e di fine', function (string $localNow): void {
    $occurrence = occurrenceAtLocal(
        $this->city,
        testCategory(),
        '2026-05-15 18:00',
        '2026-05-15 20:00',
    );

    freezeLocal($this->city, $localNow);

    expect(idsOf(EventOccurrenceQuery::for($this->city)->ongoing()->get()))
        ->toBe([(int) $occurrence->getKey()]);
})->with([
    'istante di inizio' => ['2026-05-15 18:00'],
    'istante di fine' => ['2026-05-15 20:00'],
]);

it('non mostra un evento che deve ancora cominciare', function (): void {
    occurrenceAtLocal(
        $this->city,
        testCategory(),
        '2026-05-15 21:00',
    );

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(EventOccurrenceQuery::for($this->city)->ongoing()->get())->toBeEmpty();
});

describe('senza ora di fine — scenario C2', function (): void {
    beforeEach(function (): void {
        $this->category = testCategory(['default_duration_minutes' => 180]);

        $this->concert = occurrenceAtLocal(
            $this->city,
            $this->category,
            '2026-05-15 21:00',
        );
    });

    it('è in corso alle 22:00, un\'ora dopo l\'inizio', function (): void {
        freezeLocal($this->city, '2026-05-15 22:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->ongoing()->get()))
            ->toBe([(int) $this->concert->getKey()]);
    });

    it('è ancora in corso a mezzanotte esatta, quando scadono i 180 minuti', function (): void {
        freezeLocal($this->city, '2026-05-16 00:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->ongoing()->get()))
            ->toBe([(int) $this->concert->getKey()]);
    });

    it('non è più in corso alle 00:30', function (): void {
        freezeLocal($this->city, '2026-05-16 00:30');

        expect(EventOccurrenceQuery::for($this->city)->ongoing()->get())->toBeEmpty();
    });
});

it('non mostra mai una mostra con supports_ongoing = false — scenario C3', function (): void {
    $exhibition = testCategory([
        'name' => 'Arte e mostre',
        'supports_ongoing' => false,
        'default_duration_minutes' => null,
    ]);

    occurrenceAtLocal(
        $this->city,
        $exhibition,
        '2026-05-15 10:00',
        '2026-05-15 19:00',
    );

    // Tre istanti dentro l'orario di apertura dichiarato.
    foreach (['2026-05-15 10:00', '2026-05-15 15:00', '2026-05-15 18:59'] as $localNow) {
        freezeLocal($this->city, $localNow);

        expect(EventOccurrenceQuery::for($this->city)->ongoing()->get())
            ->toBeEmpty("La mostra è comparsa in 'in corso' alle {$localNow}.");
    }
});

it('esclude gli eventi di intera giornata', function (): void {
    occurrenceAtLocal(
        $this->city,
        testCategory(),
        '2026-05-15 00:00',
        occurrence: ['is_all_day' => true],
    );

    freezeLocal($this->city, '2026-05-15 15:00');

    expect(EventOccurrenceQuery::for($this->city)->ongoing()->get())->toBeEmpty();
});

it('esclude le occorrenze che non sono in programma', function (OccurrenceStatus $status): void {
    occurrenceAtLocal(
        $this->city,
        testCategory(),
        '2026-05-15 18:00',
        '2026-05-15 20:00',
        occurrence: ['status' => $status],
    );

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(EventOccurrenceQuery::for($this->city)->ongoing()->get())->toBeEmpty();
})->with([
    'annullata' => [OccurrenceStatus::Cancelled],
    'rinviata' => [OccurrenceStatus::Postponed],
]);

it('esclude gli eventi non pubblicati: una bozza non è mai in corso', function (): void {
    occurrenceAtLocal(
        $this->city,
        testCategory(),
        '2026-05-15 18:00',
        '2026-05-15 20:00',
        event: ['status' => EventStatus::Draft, 'published_at' => null],
    );

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(EventOccurrenceQuery::for($this->city)->ongoing()->get())->toBeEmpty();
});

it('esclude gli eventi di un\'altra città', function (): void {
    $other = testCity(['name' => 'Vicenza', 'province_code' => 'VI']);

    occurrenceAtLocal(
        $other,
        testCategory(),
        '2026-05-15 18:00',
        '2026-05-15 20:00',
    );

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(EventOccurrenceQuery::for($this->city)->ongoing()->get())->toBeEmpty();
});
