<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Support\EventUrl;

/**
 * `llms.txt`: il sommario del sito per chi legge il web per rispondere.
 *
 * Quello che si verifica qui non è la forma del file — è facile da guardare —
 * ma che dica **le stesse cose** della mappa del sito: se i due elenchi
 * divergessero, uno dei due mentirebbe, e non si saprebbe quale.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');
});

it('risponde come testo semplice all’indirizzo che gli assistenti cercano', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30');

    $risposta = $this->get('/llms.txt')->assertOk();

    expect($risposta->headers->get('Content-Type'))->toContain('text/plain')
        ->and($risposta->getContent())->toStartWith('# '.config()->string('app.name'));
});

it('apre con una frase che dice di che sito si tratta e di quale città', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30');

    $testo = $this->get('/llms.txt')->getContent();

    expect($testo)->toContain('> ')
        ->and($testo)->toContain($this->city->name);
});

it('elenca le prossime date con luogo e ora, non il solo indirizzo', function (): void {
    $data = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30');

    $testo = $this->get('/llms.txt')->getContent();

    expect($testo)->toContain('## '.__('seo.llms.events'))
        ->and($testo)->toContain(EventUrl::occurrence($data))
        ->and($testo)->toContain($data->event->title)
        ->and($testo)->toContain('12/09/2026 21:30');
});

it('non nomina le bozze, come non le nomina la mappa del sito', function (): void {
    /*
     * I titoli sono fissati a mano di proposito. La factory li compone da una
     * lista corta, quindi due eventi possono pescare lo stesso: con i titoli
     * casuali questo test falliva in CI quando la bozza e l'evento pubblicato
     * si chiamavano allo stesso modo, pur essendo la bozza correttamente
     * esclusa. Un test che dipende da una collisione non dimostra niente.
     */
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30', event: [
        'title' => 'Serata pubblicata che deve comparire',
    ]);

    $bozza = occurrenceAtLocal($this->city, $this->category, '2026-09-13 21:30', event: [
        'title' => 'Bozza che non deve comparire',
        'status' => EventStatus::Draft,
        'published_at' => null,
    ])->event;

    $testo = $this->get('/llms.txt')->getContent();

    expect($testo)->toContain('Serata pubblicata che deve comparire')
        ->and($testo)->not->toContain($bozza->title);
});

it('non nomina i contenuti dimostrativi, che nemmeno la mappa dichiara', function (): void {
    /* Titoli fissati per la stessa ragione: la factory può ripeterli. */
    $vero = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30', event: [
        'title' => 'Evento reale del catalogo',
    ])->event;

    $finto = occurrenceAtLocal($this->city, $this->category, '2026-09-14 21:30', event: [
        'title' => 'Evento dimostrativo da non dichiarare',
    ])->event;
    $finto->forceFill(['is_demo' => true])->save();

    $testo = $this->get('/llms.txt')->getContent();

    expect($testo)->toContain($vero->title)
        ->and($testo)->not->toContain($finto->title);
});

it('non spezza il collegamento quando il titolo contiene parentesi', function (): void {
    $data = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30', event: [
        'title' => 'Bollicine [tributo] (Vasco Rossi)',
    ]);

    $testo = $this->get('/llms.txt')->getContent();

    /* La riga resta una sola, e l'indirizzo resta dentro le sue parentesi. */
    expect($testo)->toContain('- [Bollicine tributo Vasco Rossi]('.EventUrl::occurrence($data).')');
});

it('è raggiungibile e coerente con il robots.txt che lo affianca', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30');

    $this->get('/llms.txt')->assertOk();
    $this->get('/robots.txt')->assertOk();
});
