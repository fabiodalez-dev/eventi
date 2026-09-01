<?php

declare(strict_types=1);

use App\Enums\ApiInclude;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\TicketTierStatus;
use App\Models\TicketTier;
use App\Support\TicketTiers;

/**
 * Le fasce di prezzo: **lo stato è per fascia, non per data**.
 *
 * È la cosa che `OccurrenceStatus::SoldOut` non sa dire, ed è la ragione per
 * cui la tabella esiste: «parterre esaurito» accanto a «galleria disponibile»
 * sulla stessa serata.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

it('mostra sulla scheda le fasce con stato diverso sulla stessa data', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', event: [
        'title' => 'Concerto con due settori',
        'price_type' => PriceType::Ticket,
    ]);

    $event = $occurrence->event;

    TicketTier::factory()->soldOut()->create([
        'event_id' => $event->getKey(),
        'name' => 'Parterre in piedi',
        'price' => 25,
        'sort_order' => 0,
    ]);

    TicketTier::factory()->create([
        'event_id' => $event->getKey(),
        'name' => 'Galleria numerata',
        'price' => 32,
        'sort_order' => 1,
    ]);

    $response = $this->get('/eventi/'.$event->slug);

    $response->assertOk()
        ->assertSee('Parterre in piedi')
        ->assertSee('Galleria numerata')
        ->assertSee(TicketTierStatus::SoldOut->label())
        ->assertSee(TicketTierStatus::Available->label());

    // La data non è esaurita: solo un settore lo è.
    expect($occurrence->fresh()?->status)->toBe(OccurrenceStatus::Scheduled);
});

it('lascia il listino della data al posto di quello dell evento, senza fonderli', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00');
    $event = $occurrence->event;

    TicketTier::factory()->create(['event_id' => $event->getKey(), 'name' => 'Intero', 'price' => 20]);
    TicketTier::factory()->create(['event_id' => $event->getKey(), 'name' => 'Ridotto', 'price' => 14]);

    TicketTier::factory()->forOccurrence($occurrence)->create([
        'name' => 'Anteprima a prezzo unico',
        'price' => 10,
    ]);

    $names = TicketTiers::for($event, $occurrence)->pluck('name')->all();

    expect($names)->toBe(['Anteprima a prezzo unico'])
        ->and(TicketTiers::for($event)->pluck('name')->all())->toBe(['Intero', 'Ridotto']);
});

it('ricalcola il prezzo minimo e massimo dell evento dalle fasce', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', event: [
        'price_type' => PriceType::Ticket,
        'price_min' => null,
        'price_max' => null,
    ])->event;

    TicketTier::factory()->create(['event_id' => $event->getKey(), 'name' => 'Intero', 'price' => 30]);
    TicketTier::factory()->create(['event_id' => $event->getKey(), 'name' => 'Ridotto', 'price' => 12]);

    expect((float) $event->fresh()?->price_min)->toBe(12.0)
        ->and((float) $event->fresh()?->price_max)->toBe(30.0);
});

/**
 * La fascia esaurita continua a contare nel minimo: «da 12 €» resta
 * l'informazione utile, e far salire il prezzo in vetrina ogni volta che un
 * settore finisce racconterebbe un rincaro che non è avvenuto.
 */
it('tiene la fascia esaurita dentro il prezzo minimo', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00')->event;

    TicketTier::factory()->soldOut()->create(['event_id' => $event->getKey(), 'name' => 'Ridotto', 'price' => 12]);
    TicketTier::factory()->create(['event_id' => $event->getKey(), 'name' => 'Intero', 'price' => 30]);

    expect((float) $event->fresh()?->price_min)->toBe(12.0);
});

it('non tocca il prezzo di un evento che non ha fasce', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', event: [
        'price_type' => PriceType::Ticket,
        'price_min' => 8,
        'price_max' => 8,
    ])->event;

    expect((float) $event->fresh()?->price_min)->toBe(8.0)
        ->and((float) $event->fresh()?->price_max)->toBe(8.0);
});

/**
 * Le fasce di una singola data sono un'eccezione a quella serata: alzarle a
 * listino generale falserebbe la card di tutte le altre.
 */
it('non lascia che le fasce di una data cambino il prezzo dell evento', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', event: [
        'price_type' => PriceType::Ticket,
        'price_min' => 20,
        'price_max' => 20,
    ]);

    TicketTier::factory()->forOccurrence($occurrence)->create(['name' => 'Anteprima', 'price' => 5]);

    expect((float) $occurrence->event->fresh()?->price_min)->toBe(20.0);
});

it('espone il listino in API, in sola lettura', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', event: [
        'title' => 'Concerto con listino',
        'price_type' => PriceType::Ticket,
    ]);

    $event = $occurrence->event;

    TicketTier::factory()->soldOut()->create([
        'event_id' => $event->getKey(),
        'name' => 'Parterre',
        'price' => 25,
        'sort_order' => 0,
    ]);

    $detail = $this->getJson('/api/v1/events/'.$event->slug)->assertOk()->json('data.tiers');

    expect($detail)->toHaveCount(1)
        ->and($detail[0]['name'])->toBe('Parterre')
        ->and((float) $detail[0]['price'])->toBe(25.0)
        ->and($detail[0]['status'])->toBe(TicketTierStatus::SoldOut->value);

    $listed = $this->getJson('/api/v1/events?include='.ApiInclude::Tiers->value)
        ->assertOk()
        ->json('data.0.tiers');

    expect($listed)->toHaveCount(1)->and($listed[0]['name'])->toBe('Parterre');

    // Nessun endpoint di scrittura: il listino si compila dai pannelli.
    $this->postJson('/api/v1/events/'.$event->slug.'/tiers', ['name' => 'Nuovo'])->assertNotFound();
});

it('non elenca le fasce senza chiederle nella lista', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00')->event;

    TicketTier::factory()->create(['event_id' => $event->getKey(), 'name' => 'Intero', 'price' => 10]);

    $this->getJson('/api/v1/events')->assertOk()->assertJsonMissingPath('data.0.tiers');
});
