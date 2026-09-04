<?php

declare(strict_types=1);

use Carbon\Carbon;

/*
 * "In corso adesso" e "Inizia tra poco" si disegnano dal server dentro la
 * pagina iniziale (D51): si verificano aprendo la pagina, non pilotando un
 * componente a parte. È la differenza che conta — finché erano un componente
 * Livewire questi test passavano tutti mentre nel sito vero le due sezioni non
 * comparivano mai, perché su una pagina servita dalla cache lo script che
 * doveva andarle a prendere non veniva nemmeno iniettato.
 */

afterEach(function (): void {
    Carbon::setTestNow();
});

it('mostra "in corso" con il badge che pulsa solo quando c\'è davvero qualcosa', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 22:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', '2026-09-05 23:30:00', event: [
        'title' => 'Concerto di prova',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee(__('events.sections.ongoing'))
        ->assertSee(__('events.badge.ongoing'))
        ->assertSeeHtml('pulse-dot')
        ->assertSee('Concerto di prova');
});

it('marca "inizia tra poco" con il tempo che manca', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 20:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 20:25:00');

    $this->get('/')
        ->assertOk()
        ->assertSee(__('events.sections.starting_soon'))
        ->assertSee(__('events.badge.starting_soon', ['countdown' => '25 min']));
});

/* §8.6: nessuna delle due sezioni si disegna se la finestra è vuota. */
it('non disegna alcuna sezione quando non c\'è niente in corso né in arrivo', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 20:00:00');

    /* Domani sera: fuori da entrambe le finestre. */
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

    $this->get('/')
        ->assertOk()
        ->assertDontSee(__('events.sections.ongoing'))
        ->assertDontSee(__('events.sections.starting_soon'))
        ->assertDontSee('Nessun evento');
});

/*
 * Una mostra aperta adesso non è "in corso" nel senso di §8: non si corre a
 * vederla perché sta finendo. La sua categoria lo dichiara con
 * `supports_ongoing = false`.
 *
 * L'asserzione non è più «la mostra non compare»: sulla pagina intera compare
 * eccome, sotto "Oggi", ed è giusto così. È «la mostra non produce una sezione
 * IN CORSO» — che è la cosa che il codice deve garantire. La versione
 * precedente girava sul componente isolato, dove le altre sezioni non
 * esistevano, e non sapeva distinguere le due affermazioni.
 */
it('esclude dalle sezioni dal vivo le categorie che non sopportano il "in corso"', function (): void {
    $city = testCity();
    $mostra = testCategory(['name' => 'Arte e mostre', 'supports_ongoing' => false, 'default_duration_minutes' => 480]);

    freezeLocal($city, '2026-09-05 15:00:00');

    occurrenceAtLocal($city, $mostra, '2026-09-05 10:00:00', '2026-09-05 19:00:00', event: [
        'title' => 'Mostra permanente',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Mostra permanente')
        ->assertDontSee(__('events.sections.ongoing'))
        ->assertDontSee(__('events.badge.ongoing'));
});

/*
 * Il guasto che la conversione ha chiuso, in forma di test: la pagina iniziale
 * non deve dipendere da Livewire per mostrare ciò che sta succedendo adesso.
 * Se qualcuno rifacesse di `live-now` un componente Livewire, `livewire.min.js`
 * tornerebbe nella pagina — e con lui il difetto, che si vede solo in cache.
 */
it('non tira dentro Livewire per disegnare le sezioni dal vivo', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 22:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', '2026-09-05 23:30:00');

    $this->get('/')
        ->assertOk()
        ->assertDontSee('livewire.min.js')
        ->assertDontSee('wire:snapshot', escape: false);
});
