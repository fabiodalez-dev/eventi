<?php

declare(strict_types=1);

use App\DTOs\AccessibilityProfile;
use App\Enums\AccessibilityFeature;
use App\Enums\TransitMode;
use App\Models\Venue;

/**
 * I campi del **locale** che il disegno mostra: come arrivare, accessibilità
 * strutturata, scheda informativa, quartiere.
 *
 * Stanno sul locale e non sull'evento perché cambiano col luogo e non con la
 * serata: la fermata del tram davanti al teatro è la stessa a gennaio e a
 * luglio.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

it('mostra come arrivare sulla scheda del locale e su quella dell evento', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'name' => 'Circolo con le indicazioni',
        'transit' => [
            ['mode' => TransitMode::Tram->value, 'text' => 'Tram SIR1, fermata Ospedali.'],
            ['mode' => TransitMode::Parking->value, 'text' => 'Parcheggio interno, gratuito.'],
        ],
    ]);

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', venue: $venue);

    $this->get('/locali/'.$venue->slug)
        ->assertOk()
        ->assertSee(__('venues.detail.transit'))
        ->assertSee(TransitMode::Tram->label())
        ->assertSee('Tram SIR1, fermata Ospedali.')
        ->assertSee(TransitMode::Parking->label());

    $this->get('/eventi/'.$occurrence->event->slug)
        ->assertOk()
        ->assertSee('Tram SIR1, fermata Ospedali.');
});

it('scarta le righe di transito senza mezzo o senza testo', function (): void {
    $venue = Venue::factory()->create([
        'city_id' => $this->city->getKey(),
        'transit' => [
            ['mode' => 'astronave', 'text' => 'Non esiste'],
            ['mode' => TransitMode::Bus->value, 'text' => ''],
            ['mode' => TransitMode::Bus->value, 'text' => 'Linea 12, fermata davanti.'],
        ],
    ]);

    expect($venue->fresh()?->transit->count())->toBe(1)
        ->and($venue->fresh()?->transit->lines[0]->text)->toBe('Linea 12, fermata davanti.');
});

it('elenca solo le voci di accessibilità dichiarate presenti', function (): void {
    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'accessibility' => [
            AccessibilityFeature::StepFreeEntrance->value => true,
            // Dichiarata assente: non si stampa come divieto.
            AccessibilityFeature::TactilePath->value => false,
            // Le altre restano non dichiarate.
        ],
    ]);

    $response = $this->get('/locali/'.$venue->slug)->assertOk();

    $response->assertSee(AccessibilityFeature::StepFreeEntrance->label())
        ->assertDontSee(AccessibilityFeature::TactilePath->label())
        ->assertDontSee(AccessibilityFeature::GuideDogAllowed->label());
});

/**
 * «Non lo sappiamo» non è «no»: la distinzione è il motivo per cui il campo è
 * strutturato, e va conservata anche nel dato salvato.
 */
it('distingue voce assente da voce non dichiarata', function (): void {
    $venue = Venue::factory()->create([
        'city_id' => $this->city->getKey(),
        'accessibility' => [AccessibilityFeature::AccessibleToilets->value => false],
    ]);

    $profile = $venue->fresh()?->accessibility;

    expect($profile)->toBeInstanceOf(AccessibilityProfile::class)
        ->and($profile?->declares(AccessibilityFeature::AccessibleToilets))->toBeTrue()
        ->and($profile?->has(AccessibilityFeature::AccessibleToilets))->toBeFalse()
        ->and($profile?->declares(AccessibilityFeature::ReservedSeating))->toBeFalse()
        ->and($profile?->has(AccessibilityFeature::ReservedSeating))->toBeFalse();
});

it('legge la vecchia chiave wheelchair come ingresso senza scalini', function (): void {
    $venue = Venue::factory()->create([
        'city_id' => $this->city->getKey(),
        'accessibility' => ['wheelchair' => true],
    ]);

    expect($venue->fresh()?->accessibility->has(AccessibilityFeature::StepFreeEntrance))->toBeTrue()
        ->and($venue->fresh()?->accessibility->toArray())
        ->toBe([AccessibilityFeature::StepFreeEntrance->value => true]);
});

it('filtra gli eventi per singola voce di accessibilità', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $conServizi = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'accessibility' => [
            AccessibilityFeature::StepFreeEntrance->value => true,
            AccessibilityFeature::AccessibleToilets->value => true,
        ],
    ]);

    $soloIngresso = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'accessibility' => [AccessibilityFeature::StepFreeEntrance->value => true],
    ]);

    occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', venue: $conServizi, event: [
        'title' => 'Serata con servizi accessibili',
    ]);

    occurrenceAtLocal($this->city, $this->category, '2026-09-05 22:00:00', venue: $soloIngresso, event: [
        'title' => 'Serata con il solo ingresso',
    ]);

    $this->get('/eventi?access='.AccessibilityFeature::AccessibleToilets->value)
        ->assertOk()
        ->assertSee('Serata con servizi accessibili')
        ->assertDontSee('Serata con il solo ingresso');

    // Due voci sono in AND: chi ne chiede due ne ha bisogno di due.
    $this->get('/eventi?access='.AccessibilityFeature::AccessibleToilets->value.','.AccessibilityFeature::TactilePath->value)
        ->assertOk()
        ->assertDontSee('Serata con servizi accessibili');
});

it('mostra la scheda informativa del locale', function (): void {
    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'info' => [
            ['label' => 'Guardaroba', 'value' => 'Sì, 2 € a capo'],
            ['label' => '', 'value' => 'riga senza etichetta'],
        ],
    ]);

    $this->get('/locali/'.$venue->slug)
        ->assertOk()
        ->assertSee(__('venues.detail.info'))
        ->assertSee('Guardaroba')
        ->assertSee('Sì, 2 € a capo', false)
        ->assertDontSee('riga senza etichetta');
});

it('non disegna le sezioni del locale quando non c è niente da dire', function (): void {
    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'transit' => null,
        'info' => null,
        'accessibility' => null,
    ]);

    $this->get('/locali/'.$venue->slug)
        ->assertOk()
        ->assertDontSee(__('venues.detail.transit'))
        ->assertDontSee(__('venues.detail.info'))
        ->assertDontSee(__('venues.detail.accessibility'));
});

it('filtra e mostra il quartiere', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $portello = Venue::factory()->approved()->inZone('Portello')->create(['city_id' => $this->city->getKey()]);
    $arcella = Venue::factory()->approved()->inZone('Arcella')->create(['city_id' => $this->city->getKey()]);

    occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', venue: $portello, event: [
        'title' => 'Serata al Portello',
    ]);

    occurrenceAtLocal($this->city, $this->category, '2026-09-05 22:00:00', venue: $arcella, event: [
        'title' => 'Serata in Arcella',
    ]);

    $this->get('/eventi?zone=Portello')
        ->assertOk()
        ->assertSee('Serata al Portello')
        ->assertDontSee('Serata in Arcella');

    $this->get('/locali/'.$portello->slug)->assertOk()->assertSee('Portello');
});

it('espone in API il quartiere, come arrivare, accessibilità e scheda informativa', function (): void {
    $venue = Venue::factory()->approved()->inZone('Portello')->create([
        'city_id' => $this->city->getKey(),
        'transit' => [['mode' => TransitMode::Metro->value, 'text' => 'Fermata a due passi.']],
        'accessibility' => [AccessibilityFeature::StepFreeEntrance->value => true],
        'info' => [['label' => 'Guardaroba', 'value' => 'Gratuito']],
    ]);

    $data = $this->getJson('/api/v1/venues/'.$venue->slug)->assertOk()->json('data');

    expect($data['zone'])->toBe('Portello')
        ->and($data['transit'])->toBe([['mode' => TransitMode::Metro->value, 'text' => 'Fermata a due passi.']])
        ->and($data['accessibility'])->toBe([AccessibilityFeature::StepFreeEntrance->value => true])
        ->and($data['info'])->toBe([['label' => 'Guardaroba', 'value' => 'Gratuito']]);

    $this->getJson('/api/v1/venues?zone=Portello')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/venues?zone=Arcella')->assertOk()->assertJsonCount(0, 'data');
});
