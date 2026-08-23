<?php

declare(strict_types=1);

use App\Enums\TimeOfDay;
use App\Queries\EventOccurrenceQuery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * §8.4, finestre di giornata. Si leggono da `business_date`, non da `starts_at`:
 * è ciò che tiene un after delle 2:00 dentro la sera precedente.
 *
 * ```
 * OGGI     business_date = today(city)
 * STASERA  business_date = today(city)  AND  ora locale di starts_at >= 17:00
 * DOMANI   business_date = today + 1
 * WEEKEND  business_date ∈ {venerdì, sabato, domenica}
 * ```
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->nightlife = testCategory(['is_nightlife' => true]);
});

describe('oggi', function (): void {
    it('raccoglie la giornata evento corrente, non le ventiquattro ore dell\'orologio', function (): void {
        $afternoon = occurrenceAtLocal($this->city, $this->category, '2026-05-15 15:00');
        $evening = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00');
        // Nightlife alle 2:00 di sabato: appartiene a venerdì.
        $afterHours = occurrenceAtLocal($this->city, $this->nightlife, '2026-05-16 02:00');
        // Stesso orario, categoria diurna: è sabato, e resta fuori.
        occurrenceAtLocal($this->city, $this->category, '2026-05-16 02:00');
        occurrenceAtLocal($this->city, $this->category, '2026-05-14 21:00');

        freezeLocal($this->city, '2026-05-15 10:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->get()))->toBe([
            (int) $afternoon->getKey(),
            (int) $evening->getKey(),
            (int) $afterHours->getKey(),
        ]);
    });

    it('non cambia giornata evento perché è passata la mezzanotte, se è ancora notte fonda', function (): void {
        $evening = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00');

        // Sono le 2:00 di sabato, ma "oggi" segue l'orologio: la giornata
        // evento è già sabato e la serata di venerdì non compare più.
        freezeLocal($this->city, '2026-05-16 02:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->get()))
            ->not->toContain((int) $evening->getKey());
    });
});

describe('stasera', function (): void {
    it('parte dalle 17:00 locali e non prende il pomeriggio', function (): void {
        occurrenceAtLocal($this->city, $this->category, '2026-05-15 16:59');
        $seventeen = occurrenceAtLocal($this->city, $this->category, '2026-05-15 17:00');
        $evening = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:30');

        freezeLocal($this->city, '2026-05-15 12:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->tonight()->get()))->toBe([
            (int) $seventeen->getKey(),
            (int) $evening->getKey(),
        ]);
    });

    it('resta dentro la giornata evento corrente', function (): void {
        occurrenceAtLocal($this->city, $this->category, '2026-05-16 21:00');
        $tonight = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00');

        freezeLocal($this->city, '2026-05-15 12:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->tonight()->get()))
            ->toBe([(int) $tonight->getKey()]);
    });

    it('legge l\'ora locale con lo scostamento estivo, non con quello invernale', function (): void {
        // 15:30 UTC = 17:30 locali in ora legale. Con uno scostamento fisso
        // di +01:00 sarebbero state le 16:30 e l'evento sarebbe sparito.
        $summerEvening = occurrenceAt($this->city, $this->category, '2026-07-15 15:30:00');

        freezeLocal($this->city, '2026-07-15 12:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->tonight()->get()))
            ->toBe([(int) $summerEvening->getKey()]);
    });

    it('legge l\'ora locale con lo scostamento invernale', function (): void {
        // 16:30 UTC = 17:30 locali in ora solare.
        $winterEvening = occurrenceAt($this->city, $this->category, '2026-01-15 16:30:00');
        // 16:00 UTC = 17:00 locali: primo istante incluso.
        $edge = occurrenceAt($this->city, $this->category, '2026-01-15 16:00:00');
        // 15:59 UTC = 16:59 locali: fuori.
        occurrenceAt($this->city, $this->category, '2026-01-15 15:59:00');

        freezeLocal($this->city, '2026-01-15 12:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->tonight()->get()))->toBe([
            (int) $edge->getKey(),
            (int) $winterEvening->getKey(),
        ]);
    });
});

describe('domani', function (): void {
    it('prende la giornata evento successiva', function (): void {
        occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00');
        $tomorrow = occurrenceAtLocal($this->city, $this->category, '2026-05-16 21:00');
        occurrenceAtLocal($this->city, $this->category, '2026-05-17 21:00');

        freezeLocal($this->city, '2026-05-15 10:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->tomorrow()->get()))
            ->toBe([(int) $tomorrow->getKey()]);
    });

    it('non scavalca un giorno quando fra oggi e domani cade il cambio d\'ora', function (): void {
        // Sabato 28 marzo 2026: la notte seguente le lancette vanno avanti.
        $sunday = occurrenceAtLocal($this->city, $this->category, '2026-03-29 20:00');

        freezeLocal($this->city, '2026-03-28 22:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->tomorrow()->get()))
            ->toBe([(int) $sunday->getKey()]);
    });
});

describe('weekend', function (): void {
    it('prende venerdì, sabato e domenica quando si guarda da lunedì', function (): void {
        occurrenceAtLocal($this->city, $this->category, '2026-05-13 21:00');
        $friday = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00');
        $saturday = occurrenceAtLocal($this->city, $this->category, '2026-05-16 21:00');
        $sunday = occurrenceAtLocal($this->city, $this->category, '2026-05-17 18:00');
        occurrenceAtLocal($this->city, $this->category, '2026-05-18 21:00');

        // Lunedì 11 maggio 2026.
        freezeLocal($this->city, '2026-05-11 09:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->weekend()->get()))->toBe([
            (int) $friday->getKey(),
            (int) $saturday->getKey(),
            (int) $sunday->getKey(),
        ]);
    });

    it('non riporta indietro chi lo chiede di sabato', function (): void {
        occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00');
        $saturday = occurrenceAtLocal($this->city, $this->category, '2026-05-16 21:00');
        $sunday = occurrenceAtLocal($this->city, $this->category, '2026-05-17 18:00');

        freezeLocal($this->city, '2026-05-16 11:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->weekend()->get()))->toBe([
            (int) $saturday->getKey(),
            (int) $sunday->getKey(),
        ]);
    });

    it('tiene dentro la notte del venerdì, che appartiene ancora al venerdì', function (): void {
        $fridayNight = occurrenceAtLocal($this->city, $this->nightlife, '2026-05-16 03:00');

        freezeLocal($this->city, '2026-05-11 09:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->weekend()->get()))
            ->toContain((int) $fridayNight->getKey());
    });
});

describe('data e intervallo', function (): void {
    it('onDate seleziona una sola giornata evento', function (): void {
        $target = occurrenceAtLocal($this->city, $this->category, '2026-09-04 20:00');
        occurrenceAtLocal($this->city, $this->category, '2026-09-05 20:00');

        freezeLocal($this->city, '2026-05-15 10:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->onDate('2026-09-04')->get()))
            ->toBe([(int) $target->getKey()]);
    });

    it('onDate accetta la data della notte a cui la serata appartiene', function (): void {
        $afterHours = occurrenceAtLocal($this->city, $this->nightlife, '2026-09-05 02:00');

        freezeLocal($this->city, '2026-05-15 10:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->onDate('2026-09-04')->get()))
            ->toBe([(int) $afterHours->getKey()])
            ->and(EventOccurrenceQuery::for($this->city)->onDate('2026-09-05')->get())->toBeEmpty();
    });

    it('between comprende entrambi gli estremi', function (): void {
        occurrenceAtLocal($this->city, $this->category, '2026-09-03 20:00');
        $first = occurrenceAtLocal($this->city, $this->category, '2026-09-04 20:00');
        $middle = occurrenceAtLocal($this->city, $this->category, '2026-09-05 20:00');
        $last = occurrenceAtLocal($this->city, $this->category, '2026-09-06 20:00');
        occurrenceAtLocal($this->city, $this->category, '2026-09-07 20:00');

        freezeLocal($this->city, '2026-05-15 10:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->between('2026-09-04', '2026-09-06')->get()))->toBe([
            (int) $first->getKey(),
            (int) $middle->getKey(),
            (int) $last->getKey(),
        ]);
    });

    it('between raddrizza gli estremi passati al contrario', function (): void {
        $inside = occurrenceAtLocal($this->city, $this->category, '2026-09-05 20:00');
        occurrenceAtLocal($this->city, $this->category, '2026-09-08 20:00');

        freezeLocal($this->city, '2026-05-15 10:00');

        expect(idsOf(EventOccurrenceQuery::for($this->city)->between('2026-09-06', '2026-09-04')->get()))
            ->toBe([(int) $inside->getKey()]);
    });
});

describe('fasce orarie', function (): void {
    it('assegna ogni ora alla sua fascia, con l\'ora legale', function (TimeOfDay $band, string $localTime, bool $expected): void {
        $occurrence = occurrenceAtLocal($this->city, $this->nightlife, $localTime);

        freezeLocal($this->city, '2026-07-01 10:00');

        $ids = idsOf(EventOccurrenceQuery::for($this->city)->timeOfDay($band)->get());

        expect(in_array((int) $occurrence->getKey(), $ids, true))->toBe($expected);
    })->with([
        'giorno alle 06:00' => [TimeOfDay::Day, '2026-07-15 06:00', true],
        'giorno alle 16:59' => [TimeOfDay::Day, '2026-07-15 16:59', true],
        'giorno alle 17:00' => [TimeOfDay::Day, '2026-07-15 17:00', false],
        'sera alle 17:00' => [TimeOfDay::Evening, '2026-07-15 17:00', true],
        'sera alle 21:59' => [TimeOfDay::Evening, '2026-07-15 21:59', true],
        'sera alle 22:00' => [TimeOfDay::Evening, '2026-07-15 22:00', false],
        'notte alle 22:00' => [TimeOfDay::Night, '2026-07-15 22:00', true],
        'notte all\'01:00' => [TimeOfDay::Night, '2026-07-15 01:00', true],
        'notte alle 05:59' => [TimeOfDay::Night, '2026-07-15 05:59', true],
        'notte alle 06:00' => [TimeOfDay::Night, '2026-07-15 06:00', false],
    ]);

    it('assegna ogni ora alla sua fascia anche con l\'ora solare', function (TimeOfDay $band, string $localTime, bool $expected): void {
        $occurrence = occurrenceAtLocal($this->city, $this->nightlife, $localTime);

        freezeLocal($this->city, '2026-01-05 10:00');

        $ids = idsOf(EventOccurrenceQuery::for($this->city)->timeOfDay($band)->get());

        expect(in_array((int) $occurrence->getKey(), $ids, true))->toBe($expected);
    })->with([
        'notte alle 22:00' => [TimeOfDay::Night, '2026-01-15 22:00', true],
        'notte all\'01:00' => [TimeOfDay::Night, '2026-01-15 01:00', true],
        'notte alle 06:00' => [TimeOfDay::Night, '2026-01-15 06:00', false],
        'sera alle 18:00' => [TimeOfDay::Evening, '2026-01-15 18:00', true],
        'giorno alle 10:00' => [TimeOfDay::Day, '2026-01-15 10:00', true],
    ]);
});

describe('"adesso" appartiene alla città, non al server — §8.1', function (): void {
    it('dà due giornate evento diverse a due città in fusi diversi, nello stesso istante', function (): void {
        $rome = testCity(['timezone' => 'Europe/Rome']);
        $auckland = testCity(['name' => 'Auckland', 'timezone' => 'Pacific/Auckland']);

        $category = testCategory();

        // 23:30 UTC: a Roma è già il 16 maggio, ad Auckland è il pomeriggio del 16.
        Carbon::setTestNow(CarbonImmutable::parse('2026-05-15 23:30:00', 'UTC'));

        $romeToday = occurrenceAt($rome, $category, '2026-05-16 20:00:00');
        $aucklandToday = occurrenceAt($auckland, $category, '2026-05-16 06:00:00');

        expect(idsOf(EventOccurrenceQuery::for($rome)->today()->get()))
            ->toBe([(int) $romeToday->getKey()])
            ->and(idsOf(EventOccurrenceQuery::for($auckland)->today()->get()))
            ->toBe([(int) $aucklandToday->getKey()]);
    });
});

it('lascia fuori da "stasera" la coda notturna della stessa giornata evento', function (): void {
    // §8.4 definisce "stasera" come business_date odierna E ora locale >= 17:00.
    // Un after delle 2:00 appartiene alla serata di ieri per business_date, ma
    // la sua ora locale è 2: la seconda condizione lo esclude. È la lettera del
    // piano, ed è il punto in cui le due regole di §8.2 e §8.4 si scontrano.
    $afterHours = occurrenceAtLocal($this->city, $this->nightlife, '2026-05-16 02:00');
    $evening = occurrenceAtLocal($this->city, $this->category, '2026-05-15 21:00');

    freezeLocal($this->city, '2026-05-15 12:00');

    expect(idsOf(EventOccurrenceQuery::for($this->city)->today()->get()))
        ->toBe([(int) $evening->getKey(), (int) $afterHours->getKey()])
        ->and(idsOf(EventOccurrenceQuery::for($this->city)->tonight()->get()))
        ->toBe([(int) $evening->getKey()]);
});
