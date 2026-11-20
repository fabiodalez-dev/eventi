<?php

declare(strict_types=1);

use App\Enums\PromotionMode;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Models\Event;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\Venue;
use Carbon\CarbonImmutable;

/*
 * L'unità di consegna e il suo obiettivo di visualizzazioni.
 *
 * Una campagna singola è un'unità da sola; le righe di un'abilitazione sono
 * un'unità sola, l'abilitazione. Arrivata all'obiettivo, l'unità intera
 * smette di comparire: `visible()` resta l'unico punto che lo decide.
 *
 * Il tempo è fermo per lo stesso motivo di `SponsorshipWeightTest`: le date
 * scritte a mano non devono scivolare sotto i piedi del test.
 */
beforeEach(function (): void {
    test()->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'UTC'));

    $this->citta = testCity();
    $this->categoria = testCategory();
    $this->locale = Venue::factory()->approved()->create(['city_id' => $this->citta->getKey()]);
});

/** Un evento pubblicato del locale, con una data fra una settimana. */
function p0EventoDelLocale(): Event
{
    $occorrenza = occurrenceAtLocal(test()->citta, test()->categoria, '2026-11-27 21:00:00', venue: test()->locale);

    return $occorrenza->event;
}

/** @return list<int> */
function p0Visibili(): array
{
    return Sponsorship::query()->visible()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

it('shows a single campaign until it delivers its goal, and not one view more', function (): void {
    $campagna = Sponsorship::factory()->goal(100)->create(['event_id' => p0EventoDelLocale()->getKey()]);

    $campagna->forceFill(['impressions' => 99])->saveQuietly();
    expect(p0Visibili())->toBe([$campagna->id]);

    $campagna->forceFill(['impressions' => 100])->saveQuietly();
    expect(p0Visibili())->toBe([]);
});

it('keeps a campaign without a goal visible whatever it has delivered', function (): void {
    $campagna = Sponsorship::factory()->create(['event_id' => p0EventoDelLocale()->getKey()]);
    $campagna->forceFill(['impressions' => 1_000_000])->saveQuietly();

    expect($campagna->fresh()->impressions_goal)->toBeNull()
        ->and($campagna->fresh()->pacing)->toBe(SponsorshipPacing::Even)
        ->and(p0Visibili())->toBe([$campagna->id]);
});

it('hides every event of a grant once the grant as a whole reaches its goal', function (): void {
    $abilitazione = SponsorshipGrant::factory()->goal(100)->create(['venue_id' => $this->locale->getKey()]);
    $prima = Sponsorship::factory()->forGrant($abilitazione)->create(['event_id' => p0EventoDelLocale()->getKey()]);
    $seconda = Sponsorship::factory()->forGrant($abilitazione)->create(['event_id' => p0EventoDelLocale()->getKey()]);

    $prima->forceFill(['impressions' => 60])->saveQuietly();
    $seconda->forceFill(['impressions' => 39])->saveQuietly();
    expect(p0Visibili())->toBe([$prima->id, $seconda->id])
        ->and($abilitazione->deliveredImpressions())->toBe(99);

    /* Nessuna delle due righe è al suo tetto: è l'unità che ha finito. */
    $seconda->forceFill(['impressions' => 40])->saveQuietly();
    expect(p0Visibili())->toBe([])
        ->and($abilitazione->deliveredImpressions())->toBe(100);
});

it('counts the views of a row moved to the bin towards the grant goal', function (): void {
    $abilitazione = SponsorshipGrant::factory()->goal(100)->create(['venue_id' => $this->locale->getKey()]);
    $tolta = Sponsorship::factory()->forGrant($abilitazione)->create(['event_id' => p0EventoDelLocale()->getKey()]);
    $rimasta = Sponsorship::factory()->forGrant($abilitazione)->create(['event_id' => p0EventoDelLocale()->getKey()]);

    $tolta->forceFill(['impressions' => 70])->saveQuietly();
    $tolta->delete();
    $rimasta->forceFill(['impressions' => 29])->saveQuietly();
    expect(p0Visibili())->toBe([$rimasta->id])
        ->and($abilitazione->deliveredImpressions())->toBe(99);

    $rimasta->forceFill(['impressions' => 30])->saveQuietly();
    expect(p0Visibili())->toBe([]);
});

it('does not touch other units when one grant reaches its goal', function (): void {
    $finita = SponsorshipGrant::factory()->goal(10)->create(['venue_id' => $this->locale->getKey()]);
    $aPeriodo = SponsorshipGrant::factory()->create(['venue_id' => $this->locale->getKey()]);
    $rigaFinita = Sponsorship::factory()->forGrant($finita)->create(['event_id' => p0EventoDelLocale()->getKey()]);
    $rigaAPeriodo = Sponsorship::factory()->forGrant($aPeriodo)->create(['event_id' => p0EventoDelLocale()->getKey()]);
    $singola = Sponsorship::factory()->goal(10)->create(['event_id' => p0EventoDelLocale()->getKey()]);

    $rigaFinita->forceFill(['impressions' => 10])->saveQuietly();
    $rigaAPeriodo->forceFill(['impressions' => 5_000])->saveQuietly();
    $singola->forceFill(['impressions' => 9])->saveQuietly();

    expect(p0Visibili())->toBe([$rigaAPeriodo->id, $singola->id])
        ->and(SponsorshipGrant::query()->goalReached()->pluck('id')->all())->toBe([$finita->id]);
});

it('starts a new grant as a period product with weight 1, priority 0 and even pacing', function (): void {
    $abilitazione = SponsorshipGrant::factory()->automatic()->create(['venue_id' => $this->locale->getKey()]);

    expect($abilitazione->weight)->toBe(1)
        ->and($abilitazione->priority)->toBe(0)
        ->and($abilitazione->pacing)->toBe(SponsorshipPacing::Even)
        ->and($abilitazione->hasGoal())->toBeFalse()
        ->and($abilitazione->mode)->toBe(PromotionMode::Automatic);

    $riletta = $abilitazione->fresh();
    expect($riletta->weight)->toBe(1)
        ->and($riletta->priority)->toBe(0)
        ->and($riletta->pacing)->toBe(SponsorshipPacing::Even)
        ->and($riletta->impressions_goal)->toBeNull();
});

it('stores goal, pacing, weight and priority on the grant', function (): void {
    $abilitazione = SponsorshipGrant::factory()->goal(3_000, SponsorshipPacing::Asap)->weighted(3, 10)
        ->placement(SponsorshipPlacement::HomeCard)->create(['venue_id' => $this->locale->getKey()])->fresh();

    expect($abilitazione->impressions_goal)->toBe(3_000)
        ->and($abilitazione->pacing)->toBe(SponsorshipPacing::Asap)
        ->and($abilitazione->weight)->toBe(3)
        ->and($abilitazione->priority)->toBe(10)
        ->and($abilitazione->hasGoal())->toBeTrue()
        ->and(SponsorshipGrant::active()->whereKey($abilitazione->id)->exists())->toBeTrue();
});

it('gives the complimentary factory state the reason the rule asks for', function (): void {
    $abilitazione = SponsorshipGrant::factory()->complimentary()->create(['venue_id' => $this->locale->getKey()]);

    expect($abilitazione->complimentary)->toBeTrue()
        ->and($abilitazione->notes)->not->toBeEmpty()
        ->and(SponsorshipGrant::active()->whereKey($abilitazione->id)->exists())->toBeTrue();
});

it('names the delivery unit of a row: the grant for its rows, the row itself otherwise', function (): void {
    $abilitazione = SponsorshipGrant::factory()->create(['venue_id' => $this->locale->getKey()]);
    $riga = Sponsorship::factory()->forGrant($abilitazione)->create(['event_id' => p0EventoDelLocale()->getKey()]);
    $singola = Sponsorship::factory()->create(['event_id' => p0EventoDelLocale()->getKey()]);

    expect($riga->unitKey())->toBe('g:'.$abilitazione->id)
        ->and($abilitazione->unitKey())->toBe('g:'.$abilitazione->id)
        ->and($singola->unitKey())->toBe('s:'.$singola->id);
});

it('offers exactly the two pacing modes', function (): void {
    expect(array_keys(SponsorshipPacing::options()))->toBe(['even', 'asap']);
});
