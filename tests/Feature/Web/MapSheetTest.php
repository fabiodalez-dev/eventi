<?php

declare(strict_types=1);
use App\Models\Venue;

/**
 * Il foglio che si apre toccando un punto della mappa (§11.6).
 *
 * Si e' rotto due volte, e sempre in modo silenzioso: una perche' finiva sotto
 * le tessere di Leaflet, una perche' il suo bordo superiore restava coperto
 * dalla testata fissa. In entrambi i casi il contenuto arrivava, l'attributo
 * `hidden` spariva, e cliccare un punto sembrava non fare niente.
 *
 * Il posizionamento non si puo' verificare senza un browser. Cio' che si puo'
 * verificare qui e' tutto il resto — che l'endpoint risponda, che porti le
 * date di QUEL locale, e che il markup dichiari le due cose che l'hanno rotto.
 */
it('restituisce le date di un locale, non un frammento vuoto', function (): void {
    $city = testCity();
    $category = testCategory();

    $venue = Venue::factory()->approved()->create([
        'city_id' => $city->getKey(),
        'name' => 'Circolo di prova',
    ]);

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $venue, event: ['title' => 'Concerto al circolo']);

    $this->get(route('map.venue', ['venue' => $venue->getKey()]))
        ->assertOk()
        ->assertSee('Circolo di prova')
        ->assertSee('Concerto al circolo')
        /* Il rimando alla scheda del locale: dal foglio si deve poter uscire
           verso qualcosa di piu' grande. */
        ->assertSee(route('venues.show', $venue), escape: false);
});

it('non mostra nel foglio le date di un altro locale', function (): void {
    $city = testCity();
    $category = testCategory();

    $questo = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
    $altro = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $questo, event: ['title' => 'Data giusta']);
    occurrenceAtLocal($city, $category, '2026-09-06 22:00:00', venue: $altro, event: ['title' => 'Data sbagliata']);

    $this->get(route('map.venue', ['venue' => $questo->getKey()]))
        ->assertOk()
        ->assertSee('Data giusta')
        ->assertDontSee('Data sbagliata');
});

it('tiene il foglio sopra le tessere e sotto la testata', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

    $html = $this->get('/mappa')->assertOk()->getContent();

    /*
     * `z-[1100]`: Leaflet dispone i propri pannelli fra 200 e 700 e i propri
     * controlli a 1000. Con uno z-index piu' basso il foglio si apre DIETRO la
     * mappa — visibile solo a chi ispeziona il documento.
     *
     * `fixed` con `sm:top-header`: ancorato al riquadro invece che al viewport,
     * il bordo superiore del foglio — dove stanno il nome del locale e il
     * pulsante di chiusura — finisce sotto la testata fissa appena la pagina
     * scorre.
     */
    expect($html)
        ->toMatch('/<div[^>]*data-map-sheet[^>]*class="[^"]*\bfixed\b/')
        ->toMatch('/<div[^>]*data-map-sheet[^>]*class="[^"]*z-\[1100\]/')
        ->toMatch('/<div[^>]*data-map-sheet[^>]*class="[^"]*sm:top-header/');
});

it('etichetta ogni marcatore col nome del locale', function (): void {
    $city = testCity();
    $category = testCategory();

    $venue = Venue::factory()->approved()->create([
        'city_id' => $city->getKey(),
        'name' => 'Teatro del nome',
    ]);

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $venue);

    /*
     * Il marcatore e' un elemento con `role="button"`: senza nome si annuncia
     * col proprio identificativo numerico, e chi naviga con uno screen reader
     * sente «pulsante 24». Il nome viaggia nel carico, in sesta posizione.
     */
    $html = $this->get('/mappa')->assertOk()->getContent();

    expect($html)->toContain('Teatro del nome');
});
