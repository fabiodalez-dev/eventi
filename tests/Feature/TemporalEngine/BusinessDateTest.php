<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\City;
use Carbon\CarbonImmutable;

/**
 * §8.2 e scenario D di §18. `business_date` è la "giornata evento": un concerto
 * che comincia venerdì alle 23:30 e finisce sabato alle 3:00 è, per chi lo
 * vive, venerdì sera. Lo spostamento vale **solo** se l'ora locale sta prima
 * del cutoff notturno della città **e** la categoria è nightlife: il doppio
 * vincolo è ciò che distingue un after techno da un convegno mattutino.
 */
beforeEach(function (): void {
    $this->city = testCity();
});

/**
 * Costruisce l'occorrenza a partire dall'**ora locale** — come la scrive un
 * gestore — e restituisce la giornata evento calcolata dall'observer.
 */
function businessDateFor(City $city, Category $category, string $localStartsAt): string
{
    $startsAtUtc = CarbonImmutable::parse($localStartsAt, $city->timezone)
        ->utc()
        ->format('Y-m-d H:i:s');

    $occurrence = occurrenceAt($city, $category, $startsAtUtc);

    return $occurrence->refresh()->business_date->format('Y-m-d');
}

describe('categoria nightlife', function (): void {
    beforeEach(function (): void {
        $this->category = testCategory(['is_nightlife' => true]);
    });

    it('lascia alla sera le 23:59', function (): void {
        expect(businessDateFor($this->city, $this->category, '2026-05-15 23:59:00'))
            ->toBe('2026-05-15');
    });

    it('arretra la mezzanotte esatta al giorno prima', function (): void {
        expect(businessDateFor($this->city, $this->category, '2026-05-16 00:00:00'))
            ->toBe('2026-05-15');
    });

    it('arretra le 00:01', function (): void {
        expect(businessDateFor($this->city, $this->category, '2026-05-16 00:01:00'))
            ->toBe('2026-05-15');
    });

    it('arretra le 05:59, ultimo minuto prima del cutoff', function (): void {
        expect(businessDateFor($this->city, $this->category, '2026-05-16 05:59:00'))
            ->toBe('2026-05-15');
    });

    it('non arretra le 06:00, il cutoff è escluso', function (): void {
        expect(businessDateFor($this->city, $this->category, '2026-05-16 06:00:00'))
            ->toBe('2026-05-16');
    });

    it('non arretra le 06:01', function (): void {
        expect(businessDateFor($this->city, $this->category, '2026-05-16 06:01:00'))
            ->toBe('2026-05-16');
    });
});

describe('categoria non nightlife', function (): void {
    beforeEach(function (): void {
        $this->category = testCategory(['is_nightlife' => false]);
    });

    it('non sposta mai la giornata, a nessuna delle ore limite', function (string $localStartsAt, string $expected): void {
        expect(businessDateFor($this->city, $this->category, $localStartsAt))->toBe($expected);
    })->with([
        ['2026-05-15 23:59:00', '2026-05-15'],
        ['2026-05-16 00:00:00', '2026-05-16'],
        ['2026-05-16 00:01:00', '2026-05-16'],
        ['2026-05-16 05:59:00', '2026-05-16'],
        ['2026-05-16 06:00:00', '2026-05-16'],
        ['2026-05-16 06:01:00', '2026-05-16'],
    ]);
});

it('rispetta il cutoff notturno dichiarato dalla città, non un 06:00 cablato', function (): void {
    $city = testCity(['night_cutoff_time' => '04:00:00']);
    $category = testCategory(['is_nightlife' => true]);

    expect(businessDateFor($city, $category, '2026-05-16 03:59:00'))->toBe('2026-05-15')
        ->and(businessDateFor($city, $category, '2026-05-16 04:00:00'))->toBe('2026-05-16');
});

it('tratta come venerdì sera un concerto che finisce sabato alle 3:00 — scenario D', function (): void {
    $nightlife = testCategory(['is_nightlife' => true]);
    $ordinary = testCategory(['is_nightlife' => false]);

    // Venerdì 15 maggio 2026, 23:30 → sabato 03:00, ora legale (+02:00).
    $startsAtUtc = '2026-05-15 21:30:00';
    $endsAtUtc = '2026-05-16 01:00:00';

    $night = occurrenceAt($this->city, $nightlife, $startsAtUtc, $endsAtUtc)->refresh();
    $day = occurrenceAt($this->city, $ordinary, $startsAtUtc, $endsAtUtc)->refresh();

    expect($night->business_date->format('Y-m-d'))->toBe('2026-05-15')
        ->and($day->business_date->format('Y-m-d'))->toBe('2026-05-15');

    // La stessa serata, ma cominciata dopo la mezzanotte: solo la nightlife
    // resta attaccata a venerdì.
    $afterNight = occurrenceAt($this->city, $nightlife, '2026-05-16 00:30:00')->refresh();
    $afterDay = occurrenceAt($this->city, $ordinary, '2026-05-16 00:30:00')->refresh();

    expect($afterNight->business_date->format('Y-m-d'))->toBe('2026-05-15')
        ->and($afterDay->business_date->format('Y-m-d'))->toBe('2026-05-16');
});

describe('passaggio all\'ora legale — ultima domenica di marzo', function (): void {
    // 2026-03-29: alle 02:00 locali le lancette vanno alle 03:00.
    // Prima del salto l'Italia è a +01:00, dopo a +02:00.
    beforeEach(function (): void {
        $this->nightlife = testCategory(['is_nightlife' => true]);
    });

    it('arretra un\'occorrenza dell\'ora ancora solare', function (): void {
        // 00:30 UTC = 01:30 locali, ancora CET.
        $occurrence = occurrenceAt($this->city, $this->nightlife, '2026-03-29 00:30:00')->refresh();

        expect($occurrence->starts_at->setTimezone($this->city->timezone)->format('H:i'))->toBe('01:30')
            ->and($occurrence->business_date->format('Y-m-d'))->toBe('2026-03-28');
    });

    it('arretra un\'occorrenza subito dopo il salto in avanti', function (): void {
        // 01:00 UTC = 03:00 locali, già CEST: l'ora 02:00 locale non esiste.
        $occurrence = occurrenceAt($this->city, $this->nightlife, '2026-03-29 01:00:00')->refresh();

        expect($occurrence->starts_at->setTimezone($this->city->timezone)->format('H:i'))->toBe('03:00')
            ->and($occurrence->business_date->format('Y-m-d'))->toBe('2026-03-28');
    });

    it('non arretra il mattino della domenica, dove lo scostamento è già +02:00', function (): void {
        // 04:30 UTC = 06:30 locali.
        $occurrence = occurrenceAt($this->city, $this->nightlife, '2026-03-29 04:30:00')->refresh();

        expect($occurrence->starts_at->setTimezone($this->city->timezone)->format('H:i'))->toBe('06:30')
            ->and($occurrence->business_date->format('Y-m-d'))->toBe('2026-03-29');
    });

    it('non confonde le 06:00 locali con le 06:00 UTC', function (): void {
        // Uno scostamento fisso di +01:00 avrebbe letto le 05:00 e arretrato.
        $occurrence = occurrenceAt($this->city, $this->nightlife, '2026-03-29 04:00:00')->refresh();

        expect($occurrence->business_date->format('Y-m-d'))->toBe('2026-03-29');
    });
});

describe('passaggio all\'ora solare — ultima domenica di ottobre', function (): void {
    // 2026-10-25: alle 03:00 locali le lancette tornano alle 02:00.
    // L'ora locale 02:30 esiste due volte, a +02:00 e a +01:00.
    beforeEach(function (): void {
        $this->nightlife = testCategory(['is_nightlife' => true]);
    });

    it('arretra la prima occorrenza dell\'ora doppia', function (): void {
        // 00:30 UTC = 02:30 locali CEST.
        $occurrence = occurrenceAt($this->city, $this->nightlife, '2026-10-25 00:30:00')->refresh();

        expect($occurrence->starts_at->setTimezone($this->city->timezone)->format('H:i'))->toBe('02:30')
            ->and($occurrence->business_date->format('Y-m-d'))->toBe('2026-10-24');
    });

    it('arretra anche la seconda occorrenza dell\'ora doppia', function (): void {
        // 01:30 UTC = 02:30 locali CET, un'ora dopo la precedente.
        $occurrence = occurrenceAt($this->city, $this->nightlife, '2026-10-25 01:30:00')->refresh();

        expect($occurrence->starts_at->setTimezone($this->city->timezone)->format('H:i'))->toBe('02:30')
            ->and($occurrence->business_date->format('Y-m-d'))->toBe('2026-10-24');
    });

    it('non arretra le 06:00 locali di quella domenica', function (): void {
        // 05:00 UTC = 06:00 locali CET.
        $occurrence = occurrenceAt($this->city, $this->nightlife, '2026-10-25 05:00:00')->refresh();

        expect($occurrence->starts_at->setTimezone($this->city->timezone)->format('H:i'))->toBe('06:00')
            ->and($occurrence->business_date->format('Y-m-d'))->toBe('2026-10-25');
    });

    it('arretra le 05:59 locali di quella domenica', function (): void {
        $occurrence = occurrenceAt($this->city, $this->nightlife, '2026-10-25 04:59:00')->refresh();

        expect($occurrence->starts_at->setTimezone($this->city->timezone)->format('H:i'))->toBe('05:59')
            ->and($occurrence->business_date->format('Y-m-d'))->toBe('2026-10-24');
    });
});

it('ricalcola la giornata evento quando la categoria dell\'evento cambia', function (): void {
    $ordinary = testCategory(['is_nightlife' => false]);
    $nightlife = testCategory(['is_nightlife' => true]);

    $occurrence = occurrenceAt($this->city, $ordinary, '2026-05-15 23:30:00');

    expect($occurrence->refresh()->business_date->format('Y-m-d'))->toBe('2026-05-16');

    $event = $occurrence->event;
    $event->category_id = $nightlife->getKey();
    $event->save();

    expect($occurrence->refresh()->business_date->format('Y-m-d'))->toBe('2026-05-15');
});
