<?php

declare(strict_types=1);

use App\Models\Venue;
use App\Support\Capacity;

/**
 * Posti rimasti ed etichetta di richiamo: due campi dell'occorrenza che
 * esistevano — o quasi — e non erano mai stati mostrati.
 *
 * La regola che li governa è una sola: **niente numeri inventati**. Senza una
 * capienza credibile non si disegna la barra, e senza `capacity_left` non si
 * scrive nulla del tutto (§8.6).
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

it('mostra i posti rimasti sulla card e sulla scheda', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'capacity' => 200,
    ]);

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', venue: $venue, occurrence: [
        'capacity_left' => 40,
    ], event: ['title' => 'Serata con posti contati']);

    $this->get('/eventi')
        ->assertOk()
        ->assertSee(trans_choice('events.capacity.left', 40, ['count' => 40]));

    $this->get('/eventi/'.$occurrence->event->slug)
        ->assertOk()
        ->assertSee(trans_choice('events.capacity.left', 40, ['count' => 40]))
        ->assertSee(__('events.capacity.sold', ['percent' => 80]));
});

it('preferisce la capienza della data a quella del locale', function (): void {
    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'capacity' => 400,
    ]);

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', venue: $venue, occurrence: [
        'capacity' => 180,
        'capacity_left' => 90,
    ]);

    $capacity = Capacity::for($occurrence->load('event.venue'));

    expect($capacity?->total)->toBe(180)
        ->and($capacity?->percentSold())->toBe(50);
});

it('non calcola la percentuale senza un totale credibile', function (): void {
    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'capacity' => null,
    ]);

    $senzaTotale = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', venue: $venue, occurrence: [
        'capacity_left' => 12,
    ]);

    expect(Capacity::for($senzaTotale->load('event.venue'))?->percentSold())->toBeNull();

    // Posti rimasti maggiori della capienza: uno dei due numeri è vecchio.
    $incoerente = occurrenceAtLocal($this->city, $this->category, '2026-09-06 21:00:00', venue: $venue, occurrence: [
        'capacity' => 50,
        'capacity_left' => 80,
    ]);

    expect(Capacity::for($incoerente->load('event.venue'))?->percentSold())->toBeNull();
});

it('non dice niente sui posti quando nessuno li ha dichiarati', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00');

    expect(Capacity::for($occurrence->load('event.venue')))->toBeNull();

    $this->get('/eventi/'.$occurrence->event->slug)
        ->assertOk()
        ->assertDontSee(__('events.capacity.progress_label'));
});

it('mostra l etichetta di richiamo dove il locale l ha scritta', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', occurrence: [
        'highlight' => 'Nuova data',
    ], event: ['title' => 'Serata con richiamo']);

    $this->get('/eventi')->assertOk()->assertSee('Nuova data');
    $this->get('/eventi/'.$occurrence->event->slug)->assertOk()->assertSee('Nuova data');
});

it('espone posti ed etichetta in API', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', occurrence: [
        'capacity' => 300,
        'capacity_left' => 41,
        'highlight' => 'Ultimi posti',
    ]);

    $data = $this->getJson('/api/v1/events')->assertOk()->json('data.0');

    expect($data['capacity'])->toBe(300)
        ->and($data['capacity_left'])->toBe(41)
        ->and($data['highlight'])->toBe('Ultimi posti');
});
