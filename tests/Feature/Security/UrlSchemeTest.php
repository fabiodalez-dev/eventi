<?php

declare(strict_types=1);

use App\Models\Venue;

/**
 * Nessun `javascript:` finisce in un `href`.
 *
 * Blade sfugge il contenuto di un attributo — quindi nessuno può chiudere le
 * virgolette e scrivere markup — ma non impedisce che l'indirizzo STESSO sia
 * `javascript:`, che al clic esegue codice. Quei campi li compila chi gestisce
 * un locale o propone un evento: non sono necessariamente ostili, ma arrivano
 * da fuori e finiscono in un attributo che il browser esegue.
 *
 * La difesa sta in USCITA e non solo nella validazione: i dati già salvati
 * sono passati da validazioni di ieri, e una regola aggiunta oggi non li
 * ripulisce.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

it('non disegna il sito del locale quando lo schema esegue codice', function (): void {
    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'website' => 'javascript:alert(document.cookie)',
    ]);

    freezeLocal($this->city, '2026-09-05 12:00:00');
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00', venue: $venue);

    foreach ([route('venues.show', $venue), route('events.show', $occorrenza->event)] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect($html)->not->toContain('javascript:alert');
    }
});

it('disegna il sito del locale quando l indirizzo è normale', function (): void {
    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'website' => 'https://circolo.example',
    ]);

    freezeLocal($this->city, '2026-09-05 12:00:00');
    occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00', venue: $venue);

    $this->get(route('venues.show', $venue))
        ->assertOk()
        ->assertSee('https://circolo.example', escape: false);
});

it('non disegna i biglietti né la prenotazione con uno schema che esegue codice', function (): void {
    freezeLocal($this->city, '2026-09-05 12:00:00');

    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00', event: [
        'ticket_url' => 'javascript:alert(1)',
        'booking_url' => 'data:text/html,<script>alert(1)</script>',
    ]);

    $html = $this->get(route('events.show', $occorrenza->event))->assertOk()->getContent();

    expect($html)
        ->not->toContain('javascript:alert')
        ->not->toContain('data:text/html');
});
