<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\Venue;
use App\Services\Sponsorship\Delivery\DeliveryPlanBuilder;
use App\Services\Sponsorship\Delivery\Draw;
use App\Services\Sponsorship\Delivery\SeededDraw;
use App\Services\Sponsorship\GrantCampaigns;
use App\Services\Sponsorship\SponsorshipSelector;

/*
 * L'equità fra committenti: a parità di acquisto, lo stesso spazio.
 *
 * Prima ogni riga `sponsorships` era un posto sulla ruota, e un'abilitazione
 * automatica crea una riga per ogni evento del locale: un locale con quaranta
 * eventi prendeva quaranta posti contro uno di chi aveva sponsorizzato una
 * serata sola, allo stesso prezzo. Adesso si sceglie prima l'unità (il
 * committente), poi l'evento dentro l'unità.
 *
 * Il secondo test è quello che falliva sul codice di prima: l'ho fatto girare
 * sul selettore vecchio e la stessa unità usciva in tutte le richieste.
 */
beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-11-20 10:00');
    $this->category = testCategory();

    $grande = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Circolo con quaranta serate']);

    foreach (range(1, 40) as $giorno) {
        occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00', venue: $grande, occurrence: [
            'starts_at' => localInstant($this->city, '2026-11-20 21:00')->addDays($giorno % 20)->utc(),
        ]);
    }

    $this->grant = SponsorshipGrant::factory()->automatic()->placement(SponsorshipPlacement::ListTop)
        ->create(['venue_id' => $grande->getKey()]);
    app(GrantCampaigns::class)->sync($this->grant);

    $piccola = occurrenceAtLocal($this->city, $this->category, '2026-11-25 21:00');
    $this->single = Sponsorship::factory()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $piccola->event_id,
        'placement' => SponsorshipPlacement::ListTop,
        'weight' => 1,
        'priority' => 0,
    ]);
});

it('crea davvero quaranta righe per il locale grande', function (): void {
    expect(Sponsorship::query()->where('sponsorship_grant_id', $this->grant->getKey())->count())->toBe(40)
        ->and(Sponsorship::query()->visible()->where('placement', SponsorshipPlacement::ListTop)->count())->toBe(41);
});

it('divide lo spazio a metà fra quaranta eventi e uno, su 10.000 estrazioni', function (): void {
    $plan = app(DeliveryPlanBuilder::class)->build($this->city, SponsorshipPlacement::ListTop);
    $draw = new SeededDraw(20261119);
    $conteggio = ['g:'.$this->grant->getKey() => 0, 's:'.$this->single->getKey() => 0];

    for ($i = 0; $i < 10_000; $i++) {
        $row = $plan->draw($draw);
        $conteggio[$plan->unitOf((int) $row)]++;
    }

    expect($plan->units())->toHaveCount(2)
        ->and($plan->shares())->toBe(['g:'.$this->grant->getKey() => 0.5, 's:'.$this->single->getKey() => 0.5])
        ->and($conteggio['g:'.$this->grant->getKey()] / 10_000)->toBeGreaterThanOrEqual(0.48)->toBeLessThanOrEqual(0.52)
        ->and($conteggio['s:'.$this->single->getKey()] / 10_000)->toBeGreaterThanOrEqual(0.48)->toBeLessThanOrEqual(0.52);
});

it('dal selettore, il locale con quaranta eventi non schiaccia quello con uno', function (): void {
    /* Il caso viene dal contenitore, così lo stesso test gira anche sul
       selettore di prima (che lo ignorava e ruotava per minuto). */
    app()->instance(Draw::class, new SeededDraw(7));
    $selettore = app(SponsorshipSelector::class);
    $piccolo = 0;

    for ($i = 0; $i < 400; $i++) {
        if ($selettore->first($this->city, SponsorshipPlacement::ListTop)?->getKey() === $this->single->getKey()) {
            $piccolo++;
        }
    }

    /* Atteso 200, deviazione 10: fra 160 e 240 sono quattro deviazioni.
       Sul codice di prima usciva 0 oppure 400. */
    expect($piccolo)->toBeGreaterThanOrEqual(160)->toBeLessThanOrEqual(240);
});

it('dentro il locale grande preferisce le serate vicine senza lasciarne nessuna a zero', function (): void {
    $plan = app(DeliveryPlanBuilder::class)->build($this->city, SponsorshipPlacement::ListTop);
    $unit = $plan->units()['g:'.$this->grant->getKey()];
    $shares = $plan->eventShares($unit);

    expect($shares)->toHaveCount(40)
        ->and(array_sum($shares))->toEqualWithDelta(1.0, 1e-9)
        ->and(min($shares))->toBeGreaterThan(0.0)
        ->and(max($shares))->toBeGreaterThan(min($shares) * 5);
});
