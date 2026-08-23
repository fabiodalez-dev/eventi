<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Queries\EventOccurrenceQuery;

/**
 * §8.4 e scenario B di §18: sono le 19:00, un evento alle 19:30 appare, uno
 * alle 22:30 no.
 *
 * ```
 * INIZIA TRA POCO
 *   starts_at > now  AND  starts_at <= now + city.starting_soon_minutes
 *   AND status = scheduled
 * ```
 */
beforeEach(function (): void {
    $this->city = testCity();
});

it('mostra l\'evento delle 19:30 e non quello delle 22:30 — scenario B', function (): void {
    $soon = occurrenceAtLocal($this->city, testCategory(), '2026-05-15 19:30');
    occurrenceAtLocal($this->city, testCategory(), '2026-05-15 22:30');

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->startingSoon()->get()))
        ->toBe([(int) $soon->getKey()]);
});

it('comprende il limite esatto dei 180 minuti ed esclude il minuto dopo', function (): void {
    $onTheEdge = occurrenceAtLocal($this->city, testCategory(), '2026-05-15 22:00');
    occurrenceAtLocal($this->city, testCategory(), '2026-05-15 22:01');

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->startingSoon()->get()))
        ->toBe([(int) $onTheEdge->getKey()]);
});

it('esclude un evento già cominciato: quello è "in corso", non "inizia tra poco"', function (): void {
    occurrenceAtLocal($this->city, testCategory(), '2026-05-15 19:00');
    occurrenceAtLocal($this->city, testCategory(), '2026-05-15 18:30');

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(EventOccurrenceQuery::for($this->city)->startingSoon()->get())->toBeEmpty();
});

it('usa la finestra dichiarata dalla città, non 180 minuti cablati', function (): void {
    $city = testCity(['starting_soon_minutes' => 60]);

    $within = occurrenceAtLocal($city, testCategory(), '2026-05-15 19:45');
    occurrenceAtLocal($city, testCategory(), '2026-05-15 20:30');

    freezeLocal($city, '2026-05-15 19:00');

    expect(idsOf(EventOccurrenceQuery::for($city)->startingSoon()->get()))
        ->toBe([(int) $within->getKey()]);
});

it('attraversa la mezzanotte senza perdere gli eventi della notte', function (): void {
    $afterMidnight = occurrenceAtLocal($this->city, testCategory(['is_nightlife' => true]), '2026-05-16 00:30');

    freezeLocal($this->city, '2026-05-15 23:00');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->startingSoon()->get()))
        ->toBe([(int) $afterMidnight->getKey()]);
});

it('resta corretto a cavallo del passaggio all\'ora legale', function (): void {
    // Le lancette saltano da 02:00 a 03:00: fra le 01:30 e le 03:30 locali
    // passano sessanta minuti veri, non centoventi.
    $justAfterTheJump = occurrenceAtLocal($this->city, testCategory(['is_nightlife' => true]), '2026-03-29 03:30');

    freezeLocal($this->city, '2026-03-29 01:30');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->startingSoon()->get()))
        ->toBe([(int) $justAfterTheJump->getKey()]);
});

it('esclude le occorrenze annullate', function (): void {
    occurrenceAtLocal(
        $this->city,
        testCategory(),
        '2026-05-15 19:30',
        occurrence: ['status' => OccurrenceStatus::Cancelled],
    );

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(EventOccurrenceQuery::for($this->city)->startingSoon()->get())->toBeEmpty();
});

it('esclude gli eventi non pubblicati', function (): void {
    occurrenceAtLocal(
        $this->city,
        testCategory(),
        '2026-05-15 19:30',
        event: ['status' => EventStatus::Pending, 'published_at' => null],
    );

    freezeLocal($this->city, '2026-05-15 19:00');

    expect(EventOccurrenceQuery::for($this->city)->startingSoon()->get())->toBeEmpty();
});
