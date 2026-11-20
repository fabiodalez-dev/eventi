<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\SponsorshipSlotHourly;
use App\Services\Sponsorship\Delivery\TrafficHistory;
use Carbon\CarbonImmutable;

/*
 * Il traffico passato di uno spazio, nella forma che serve alla previsione:
 * settimane dalla più recente, 168 ore ciascuna, nell'ora locale della città.
 *
 * Venerdì 20 novembre 2026 alle 18:30 di Padova: l'ora in corso (18–19) non
 * è ancora finita e non entra; la settimana 0 va da venerdì 13 alle 18 a
 * venerdì 20 alle 18.
 */
beforeEach(function (): void {
    $this->citta = testCity();
    $this->adesso = CarbonImmutable::parse('2026-11-20 18:30:00', 'Europe/Rome');
});

/** Una riga di traffico, scritta come la scriverebbe chi conta. */
function p0Traffico(City $citta, string $giorno, int $ora, int $viste, string $canale = 'web', string $collocazione = 'home_card', int $sponsorizzate = 0, int $clic = 0): void
{
    SponsorshipSlotHourly::query()->create([
        'city_id' => $citta->getKey(), 'placement' => $collocazione, 'channel' => $canale, 'day' => $giorno, 'hour' => $ora,
        'views' => $viste, 'sponsored_views' => $sponsorizzate, 'clicks' => $clic,
    ]);
}

/** Lunedì = 0, ora per ora: l'indice di un'ora della settimana. */
function p0Ora(int $giornoIso, int $ora): int
{
    return ($giornoIso - 1) * 24 + $ora;
}

it('puts each hour in the right week and the right hour of the week', function (): void {
    p0Traffico($this->citta, '2026-11-20', 17, 11);          // venerdì, settimana 0
    p0Traffico($this->citta, '2026-11-13', 18, 22);          // venerdì della settimana prima, prima ora della settimana 0
    p0Traffico($this->citta, '2026-11-13', 17, 33);          // un'ora prima: settimana 1
    p0Traffico($this->citta, '2026-11-15', 21, 44);          // domenica sera, settimana 0
    p0Traffico($this->citta, '2026-10-24', 0, 55);           // sabato a mezzanotte, settimana 3

    $storia = (new TrafficHistory)->weekly($this->citta, SponsorshipPlacement::HomeCard, $this->adesso);

    expect($storia['weeks'])->toHaveCount(4)
        ->and($storia['weeks'][0])->toHaveCount(168)
        ->and($storia['weeks'][0][p0Ora(5, 17)])->toBe(11)
        ->and($storia['weeks'][0][p0Ora(5, 18)])->toBe(22)
        ->and($storia['weeks'][0][p0Ora(7, 21)])->toBe(44)
        ->and(array_sum($storia['weeks'][0]))->toBe(77)
        ->and($storia['weeks'][1][p0Ora(5, 17)])->toBe(33)
        ->and(array_sum($storia['weeks'][1]))->toBe(33)
        ->and($storia['weeks'][2])->toBeNull()
        ->and($storia['weeks'][3][p0Ora(6, 0)])->toBe(55)
        ->and($storia['days'])->toBe(4);
});

it('leaves out the hour in progress, the future and what is older than four weeks', function (): void {
    p0Traffico($this->citta, '2026-11-20', 18, 1_000);       // l'ora in corso
    p0Traffico($this->citta, '2026-11-21', 9, 1_000);        // domani
    p0Traffico($this->citta, '2026-10-23', 17, 1_000);       // un'ora prima dell'inizio della finestra
    p0Traffico($this->citta, '2026-10-23', 18, 7);           // la prima ora della finestra

    $storia = (new TrafficHistory)->weekly($this->citta, 'home_card', $this->adesso);

    expect($storia['weeks'][3][p0Ora(5, 18)])->toBe(7)
        ->and(array_sum($storia['weeks'][3]))->toBe(7)
        ->and(array_filter(array_slice($storia['weeks'], 0, 3)))->toBe([])
        ->and($storia['days'])->toBe(1);
});

it('adds up the channels unless one is asked for, and keeps placements and cities apart', function (): void {
    $altra = City::factory()->create(['timezone' => 'Europe/Rome']);

    p0Traffico($this->citta, '2026-11-19', 20, 30, 'web');
    p0Traffico($this->citta, '2026-11-19', 20, 12, 'android');
    p0Traffico($this->citta, '2026-11-19', 20, 500, 'web', 'list_top');
    p0Traffico($altra, '2026-11-19', 20, 900, 'web');

    $storia = new TrafficHistory;

    expect($storia->weekly($this->citta, 'home_card', $this->adesso)['weeks'][0][p0Ora(4, 20)])->toBe(42)
        ->and($storia->weekly($this->citta, 'home_card', $this->adesso, channel: 'android')['weeks'][0][p0Ora(4, 20)])->toBe(12)
        ->and($storia->weekly($this->citta, 'list_top', $this->adesso)['weeks'][0][p0Ora(4, 20)])->toBe(500);
});

it('reads the hours in the local time of the city, across the end of summer time', function (): void {
    /* 25 ottobre 2026: in Italia l'ora legale finisce. Domenica alle 12 locali. */
    p0Traffico($this->citta, '2026-10-25', 12, 8);
    $lunedi = CarbonImmutable::parse('2026-10-26 09:15:00', 'Europe/Rome');

    $storia = (new TrafficHistory)->weekly($this->citta, 'home_card', $lunedi->utc());

    expect($storia['weeks'][0][p0Ora(7, 12)])->toBe(8);
});

it('can look back fewer or more weeks than four', function (): void {
    p0Traffico($this->citta, '2026-11-01', 10, 5);           // domenica, settimana 2

    $storia = new TrafficHistory;

    expect($storia->weekly($this->citta, 'home_card', $this->adesso, weeks: 2)['weeks'])->toBe([null, null])
        ->and($storia->weekly($this->citta, 'home_card', $this->adesso, weeks: 6)['weeks'])->toHaveCount(6)
        ->and($storia->weekly($this->citta, 'home_card', $this->adesso, weeks: 6)['weeks'][2][p0Ora(7, 10)])->toBe(5);
});

it('answers with no data at all for a space never measured', function (): void {
    expect((new TrafficHistory)->weekly($this->citta, 'map_sheet', $this->adesso))
        ->toBe(['days' => 0, 'weeks' => [null, null, null, null]]);
});

it('gives the average click rate of the last 28 days, and nothing without sponsored views', function (): void {
    p0Traffico($this->citta, '2026-11-20', 9, 400, sponsorizzate: 300, clic: 6);
    p0Traffico($this->citta, '2026-10-24', 9, 400, sponsorizzate: 100, clic: 2);      // 27 giorni fa: dentro
    p0Traffico($this->citta, '2026-10-23', 9, 400, sponsorizzate: 1_000, clic: 900);  // 28 giorni fa: fuori
    p0Traffico($this->citta, '2026-11-20', 9, 400, 'web', 'list_top');

    $storia = new TrafficHistory;

    expect($storia->clickRate($this->citta, SponsorshipPlacement::HomeCard, $this->adesso))->toBe(0.02)
        ->and($storia->clickRate($this->citta, 'list_top', $this->adesso))->toBeNull()
        ->and($storia->clickRate($this->citta, 'map_sheet', $this->adesso))->toBeNull();
});
