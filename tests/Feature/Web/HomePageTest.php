<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('risponde e chiama il prodotto con il nome che sta nella configurazione', function (): void {
    testCity();

    $this->get('/')
        ->assertOk()
        ->assertSee(config()->string('app.name'));
});

/*
 * §8.6: una finestra temporale senza eventi non produce alcuna sezione. Non un
 * contenitore vuoto, non la scritta "nessun evento".
 */
it('non disegna nessuna sezione quando non c\'è niente in programma', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 20:00:00');

    $response = $this->get('/')->assertOk();

    $response->assertDontSee(__('events.sections.ongoing'))
        ->assertDontSee(__('events.sections.starting_soon'))
        ->assertDontSee(__('events.sections.tonight'))
        ->assertDontSee(__('events.sections.weekend'))
        ->assertDontSee('Nessun evento');
});

/*
 * "In corso" e "Inizia tra poco" non stanno più nella pagina: sono un
 * componente Livewire caricato dopo il primo disegno (§12.3). Nella pagina si
 * verifica che ci sia il frammento; il contenuto si verifica sul componente,
 * in tests/Feature/Web/LiveNowTest.php.
 */
it('rimanda "in corso" e "inizia tra poco" a un frammento caricato dopo la pagina', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 22:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', '2026-09-05 23:30:00', event: [
        'title' => 'Concerto di prova',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee(__('events.sections.live_loading'))
        ->assertDontSee(__('events.sections.ongoing'))
        ->assertDontSee(__('events.badge.ongoing'));
});

it('scrive "stasera" con l\'orario e il prezzo sulla card', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 15:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:30:00', event: [
        'price_type' => PriceType::Free,
        'price_min' => null,
        'price_max' => null,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee(__('events.sections.tonight'))
        /* L'orario, non l'etichetta che lo incornicia: la card lo scrive nella
           riga della data («oggi alle 21:30») invece che in un badge, e cio'
           che conta e' che chi guarda sappia a che ora — non in quale forma
           gliela si dice. */
        ->assertSee('21:30')
        ->assertSee(__('events.price.free'));
});

it('dichiara annullata una data annullata invece di nasconderla', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 15:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:30:00', occurrence: [
        'status' => OccurrenceStatus::Cancelled,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee(__('events.badge.cancelled'));
});

it('dichiara le misure di ogni immagine per non far ballare la pagina', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 15:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:30:00');

    /*
     * Prima questo test cercava `aspect-[3/4]`, il rapporto della locandina
     * nelle card. Le card non hanno piu' locandina (D46) — ma cio' che il test
     * proteggeva non era quella classe: era che la pagina non si sposti sotto
     * il dito mentre le immagini arrivano. Quella garanzia vale per ogni
     * immagine che resta, e si verifica su tutte invece che su una.
     */
    $html = $this->get('/')->assertOk()->getContent();

    preg_match_all('#<img\b[^>]*>#s', $html, $immagini);

    foreach ($immagini[0] as $tag) {
        expect($tag)->toContain('width=')->toContain('height=');
    }
});

it('mostra l\'attribuzione a OpenStreetMap, che la licenza dei dati impone', function (): void {
    testCity();

    $this->get('/')
        ->assertOk()
        ->assertSee('openstreetmap.org/copyright', escape: false);
});

it('offre il salto al contenuto a chi naviga da tastiera', function (): void {
    testCity();

    $this->get('/')
        ->assertOk()
        ->assertSee(__('ui.skip_to_content'))
        ->assertSee('id="contenuto"', escape: false);
});
