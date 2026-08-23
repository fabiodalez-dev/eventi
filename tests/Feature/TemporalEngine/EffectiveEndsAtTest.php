<?php

declare(strict_types=1);

use App\Models\Venue;

/**
 * §8.3. Senza `effective_ends_at` la sezione "in corso" resterebbe quasi sempre
 * vuota: la stragrande maggioranza degli eventi inseriti da un gestore ha solo
 * l'ora di inizio.
 *
 * ```
 * effective_ends_at =
 *     ends_at                                        se presente
 *     fine dell'orario di apertura del giorno        se is_all_day
 *     starts_at + category.default_duration_minutes  altrimenti
 * ```
 */
beforeEach(function (): void {
    $this->city = testCity();
});

it('usa l\'ora di fine dichiarata, quando c\'è', function (): void {
    $occurrence = occurrenceAt(
        $this->city,
        testCategory(['default_duration_minutes' => 180]),
        '2026-05-15 16:00:00',
        '2026-05-15 22:15:00',
    )->refresh();

    expect($occurrence->effective_ends_at->utc()->format('Y-m-d H:i:s'))
        ->toBe('2026-05-15 22:15:00');
});

it('somma la durata predefinita della categoria quando l\'ora di fine manca', function (): void {
    $occurrence = occurrenceAt(
        $this->city,
        testCategory(['default_duration_minutes' => 180]),
        '2026-05-15 19:00:00',
    )->refresh();

    expect($occurrence->effective_ends_at->utc()->format('Y-m-d H:i:s'))
        ->toBe('2026-05-15 22:00:00');
});

it('usa la durata della categoria a cui l\'evento appartiene davvero', function (): void {
    $occurrence = occurrenceAt(
        $this->city,
        testCategory(['default_duration_minutes' => 300]),
        '2026-05-15 20:00:00',
    )->refresh();

    expect($occurrence->effective_ends_at->utc()->format('Y-m-d H:i:s'))
        ->toBe('2026-05-16 01:00:00');
});

it('ricade sulla fine della giornata locale se la categoria non dichiara una durata', function (): void {
    $occurrence = occurrenceAt(
        $this->city,
        testCategory(['default_duration_minutes' => null]),
        '2026-05-15 12:00:00',
    )->refresh();

    // 23:59:59 locali del 15 maggio, con scostamento estivo +02:00.
    expect($occurrence->effective_ends_at->setTimezone($this->city->timezone)->format('Y-m-d H:i:s'))
        ->toBe('2026-05-15 23:59:59');
});

it('attraversa il cambio d\'ora sommando minuti reali, non minuti di orologio', function (): void {
    // 00:30 UTC = 01:30 locali CET; tre ore dopo sono le 05:30 locali CEST,
    // perché nel mezzo le lancette sono saltate da 02:00 a 03:00.
    $occurrence = occurrenceAt(
        $this->city,
        testCategory(['default_duration_minutes' => 180]),
        '2026-03-29 00:30:00',
    )->refresh();

    expect($occurrence->effective_ends_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-03-29 03:30:00')
        ->and($occurrence->effective_ends_at->setTimezone($this->city->timezone)->format('H:i'))->toBe('05:30');
});

describe('eventi di intera giornata', function (): void {
    it('finisce con la chiusura del locale nel giorno dell\'occorrenza', function (): void {
        $venue = Venue::factory()->approved()->create([
            'city_id' => $this->city->getKey(),
            'opening_hours' => [
                'fri' => [['open' => '10:00', 'close' => '20:00']],
                'sat' => [['open' => '10:00', 'close' => '23:00']],
            ],
        ]);

        // Venerdì 15 maggio 2026, intera giornata.
        $occurrence = occurrenceAt(
            $this->city,
            testCategory(['default_duration_minutes' => 120]),
            '2026-05-14 22:00:00',
            occurrence: ['is_all_day' => true],
            venue: $venue,
        )->refresh();

        expect($occurrence->effective_ends_at->setTimezone($this->city->timezone)->format('Y-m-d H:i'))
            ->toBe('2026-05-15 20:00');
    });

    it('prende la fascia che chiude più tardi, se il locale ne dichiara più d\'una', function (): void {
        $venue = Venue::factory()->approved()->create([
            'city_id' => $this->city->getKey(),
            'opening_hours' => [
                'fri' => [
                    ['open' => '10:00', 'close' => '13:00'],
                    ['open' => '17:00', 'close' => '01:00'],
                ],
            ],
        ]);

        $occurrence = occurrenceAt(
            $this->city,
            testCategory(),
            '2026-05-14 22:00:00',
            occurrence: ['is_all_day' => true],
            venue: $venue,
        )->refresh();

        // La fascia che chiude prima di aprire attraversa la mezzanotte (D19).
        expect($occurrence->effective_ends_at->setTimezone($this->city->timezone)->format('Y-m-d H:i'))
            ->toBe('2026-05-16 01:00');
    });

    it('ricade sulla fine della giornata locale se il locale non dichiara orari', function (): void {
        $venue = Venue::factory()->approved()->create([
            'city_id' => $this->city->getKey(),
            'opening_hours' => null,
        ]);

        $occurrence = occurrenceAt(
            $this->city,
            testCategory(['default_duration_minutes' => 120]),
            '2026-05-14 22:00:00',
            occurrence: ['is_all_day' => true],
            venue: $venue,
        )->refresh();

        expect($occurrence->effective_ends_at->setTimezone($this->city->timezone)->format('Y-m-d H:i:s'))
            ->toBe('2026-05-15 23:59:59');
    });

    it('non applica la durata di categoria: un\'intera giornata non dura due ore', function (): void {
        $occurrence = occurrenceAt(
            $this->city,
            testCategory(['default_duration_minutes' => 120]),
            '2026-05-14 22:00:00',
            occurrence: ['is_all_day' => true],
        )->refresh();

        expect($occurrence->effective_ends_at->setTimezone($this->city->timezone)->format('Y-m-d'))
            ->toBe('2026-05-15');
    });

    it('lascia comunque vincere l\'ora di fine dichiarata', function (): void {
        $occurrence = occurrenceAt(
            $this->city,
            testCategory(),
            '2026-05-14 22:00:00',
            '2026-05-17 16:00:00',
            occurrence: ['is_all_day' => true],
        )->refresh();

        expect($occurrence->effective_ends_at->utc()->format('Y-m-d H:i:s'))
            ->toBe('2026-05-17 16:00:00');
    });
});

it('ricalcola l\'ora di fine stimata quando cambia la categoria dell\'evento', function (): void {
    $short = testCategory(['default_duration_minutes' => 60]);
    $long = testCategory(['default_duration_minutes' => 300]);

    $occurrence = occurrenceAt($this->city, $short, '2026-05-15 19:00:00');

    expect($occurrence->refresh()->effective_ends_at->utc()->format('H:i'))->toBe('20:00');

    $event = $occurrence->event;
    $event->category_id = $long->getKey();
    $event->save();

    expect($occurrence->refresh()->effective_ends_at->utc()->format('H:i'))->toBe('00:00');
});

it('ricalcola l\'ora di fine stimata quando l\'orario di inizio si sposta', function (): void {
    $occurrence = occurrenceAt($this->city, testCategory(['default_duration_minutes' => 180]), '2026-05-15 19:00:00');

    $occurrence->starts_at = $occurrence->starts_at->addHours(2);
    $occurrence->save();

    expect($occurrence->refresh()->effective_ends_at->utc()->format('Y-m-d H:i:s'))
        ->toBe('2026-05-16 00:00:00');
});
