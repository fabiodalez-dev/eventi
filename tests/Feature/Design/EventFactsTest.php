<?php

declare(strict_types=1);

use App\DTOs\Fact;
use App\DTOs\FactList;

/**
 * La scheda tecnica dell'evento (`events.facts`): coppie etichetta/valore.
 *
 * È dell'evento e non del locale perché cambia con la serata — la stessa sala
 * apre alle 19:30 per il concerto e alle 20:45 per lo spettacolo.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

it('mostra la scheda tecnica sulla pagina dell evento', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', event: [
        'facts' => [
            ['label' => 'Apertura porte', 'value' => '19:30'],
            ['label' => 'Età minima', 'value' => '18 anni'],
        ],
    ]);

    $this->get('/eventi/'.$occurrence->event->slug)
        ->assertOk()
        ->assertSee(__('events.detail.facts'))
        ->assertSee('Apertura porte')
        ->assertSee('19:30')
        ->assertSee('Età minima');
});

it('non disegna la scheda tecnica quando non ci sono righe', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', event: ['facts' => null]);

    $this->get('/eventi/'.$occurrence->event->slug)
        ->assertOk()
        ->assertDontSee(__('events.detail.facts'));
});

it('scarta le righe senza etichetta o senza valore e salva null quando resta niente', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $event = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', event: [
        'facts' => [
            ['label' => 'Durata', 'value' => ''],
            ['label' => '', 'value' => '90 minuti'],
        ],
    ])->event;

    expect($event->fresh()?->facts->isEmpty())->toBeTrue()
        ->and($event->fresh()?->getRawOriginal('facts'))->toBeNull();
});

it('si ferma al tetto di righe', function (): void {
    $rows = [];

    for ($i = 0; $i < FactList::MAX_FACTS + 5; $i++) {
        $rows[] = ['label' => 'Voce '.$i, 'value' => 'Valore '.$i];
    }

    expect(FactList::fromMixed($rows)->count())->toBe(FactList::MAX_FACTS);
});

it('rifiuta etichette e valori troppo lunghi', function (): void {
    expect(Fact::tryFrom(['label' => str_repeat('a', Fact::MAX_LABEL_LENGTH + 1), 'value' => 'x']))->toBeNull()
        ->and(Fact::tryFrom(['label' => 'x', 'value' => str_repeat('a', Fact::MAX_VALUE_LENGTH + 1)]))->toBeNull()
        ->and(Fact::tryFrom(['label' => ' Durata ', 'value' => ' 90 minuti ']))
        ->not->toBeNull();
});

it('espone la scheda tecnica in API come lista, anche vuota', function (): void {
    freezeLocal($this->city, '2026-09-01 10:00:00');

    $conFacts = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00:00', event: [
        'facts' => [['label' => 'Durata', 'value' => '90 minuti']],
    ])->event;

    $senzaFacts = occurrenceAtLocal($this->city, $this->category, '2026-09-06 21:00:00')->event;

    expect($this->getJson('/api/v1/events/'.$conFacts->slug)->assertOk()->json('data.facts'))
        ->toBe([['label' => 'Durata', 'value' => '90 minuti']])
        ->and($this->getJson('/api/v1/events/'.$senzaFacts->slug)->assertOk()->json('data.facts'))
        ->toBe([]);
});
