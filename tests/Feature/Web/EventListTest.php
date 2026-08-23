<?php

declare(strict_types=1);

use App\Enums\PriceType;
use App\Models\Tag;
use App\Models\Venue;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('elenca solo ciò che deve ancora succedere', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-01 21:00:00', event: ['title' => 'Serata già passata']);
    occurrenceAtLocal($city, $category, '2026-09-08 21:00:00', event: ['title' => 'Serata futura']);

    $this->get('/eventi')
        ->assertOk()
        ->assertSee('Serata futura')
        ->assertDontSee('Serata già passata');
});

/*
 * §11.3: «tutti i filtri finiscono nella query string. L'URL deve essere
 * condivisibile, indicizzabile e riproducibile».
 */
it('rende la stessa pagina dalla rotta parlante e dalla query string', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $teatro = testCategory(['name' => 'Teatro e danza']);

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $musica, '2026-09-05 21:30:00', event: ['title' => 'Concerto in cortile']);
    occurrenceAtLocal($city, $teatro, '2026-09-05 21:00:00', event: ['title' => 'Spettacolo di prosa']);

    $parlante = $this->get('/eventi/oggi?category=musica-dal-vivo')->assertOk();
    $queryString = $this->get('/eventi?date=today&category=musica-dal-vivo')->assertOk();

    foreach ([$parlante, $queryString] as $response) {
        $response->assertSee('Concerto in cortile')->assertDontSee('Spettacolo di prosa');
    }
});

it('porta da una categoria a "stasera" e a "gratis" senza perdere i filtri per strada', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);

    freezeLocal($city, '2026-09-05 15:00:00');

    occurrenceAtLocal($city, $musica, '2026-09-05 21:30:00', event: [
        'title' => 'Concerto gratuito',
        'price_type' => PriceType::Free,
        'price_min' => null,
        'price_max' => null,
    ]);

    occurrenceAtLocal($city, $musica, '2026-09-05 21:30:00', event: [
        'title' => 'Concerto a pagamento',
        'price_type' => PriceType::Ticket,
        'price_min' => 12,
        'price_max' => 12,
    ]);

    occurrenceAtLocal($city, $musica, '2026-09-05 11:00:00', event: ['title' => 'Concerto del mattino']);

    /* Prima interazione: la categoria dalla griglia della homepage. */
    $this->get('/eventi/categoria/musica-dal-vivo')
        ->assertOk()
        ->assertSee('Concerto del mattino');

    /* Seconda: la pillola "stasera". Terza: la pillola "gratis". */
    $this->get('/eventi?category=musica-dal-vivo&date=tonight&price=free')
        ->assertOk()
        ->assertSee('Concerto gratuito')
        ->assertDontSee('Concerto a pagamento')
        ->assertDontSee('Concerto del mattino');
});

it('scrive titolo, intestazione e descrizione diversi per ogni combinazione', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);

    freezeLocal($city, '2026-09-05 15:00:00');

    occurrenceAtLocal($city, $musica, '2026-09-05 21:30:00', event: [
        'price_type' => PriceType::Free,
        'price_min' => null,
        'price_max' => null,
    ]);

    $this->get('/eventi')->assertOk()->assertSee('<title>Eventi a Padova', escape: false);

    $this->get('/eventi?date=tonight&category=musica-dal-vivo&price=free')
        ->assertOk()
        ->assertSee('<title>Musica dal vivo gratis stasera a Padova', escape: false)
        ->assertSee('<h1 class="text-balance text-hero text-ink">Musica dal vivo gratis stasera a Padova', escape: false);
});

it('dichiara il canonico e toglie dall\'indice le combinazioni infinite', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00');

    /* Una rotta parlante che esprime da sola tutta la richiesta. */
    $this->get('/eventi?date=today')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.route('events.today').'">', escape: false)
        ->assertSee('<meta name="robots" content="index, follow">', escape: false);

    /* Una ricerca libera non merita di stare in un indice. */
    $this->get('/eventi?q=concerto')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);
});

it('resta navigabile senza JavaScript con la paginazione ?page=', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    foreach (range(1, 30) as $index) {
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Evento numero '.$index]);
    }

    $first = $this->get('/eventi')->assertOk();
    $first->assertSee('?page=2', escape: false);

    $this->get('/eventi?page=2')
        ->assertOk()
        ->assertSee(__('ui.pagination.previous'));
});

it('filtra per tag, comune, locale, all\'aperto e accessibilità', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $tag = Tag::factory()->create(['name' => 'punk', 'is_approved' => true]);

    $accessibile = Venue::factory()->approved()->create([
        'city_id' => $city->getKey(),
        'name' => 'Circolo Accessibile',
        'municipality' => 'Este',
        'accessibility' => ['wheelchair' => true],
    ]);

    $altro = Venue::factory()->approved()->create([
        'city_id' => $city->getKey(),
        'name' => 'Sala Scalini',
        'municipality' => 'Padova',
        'accessibility' => ['wheelchair' => false],
    ]);

    $conTag = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $accessibile, event: [
        'title' => 'Concerto punk allaperto',
        'is_outdoor' => true,
    ]);

    $conTag->event->tags()->attach($tag);

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $altro, event: [
        'title' => 'Concerto al chiuso',
        'is_outdoor' => false,
    ]);

    $this->get('/eventi/tag/punk')->assertOk()->assertSee('Concerto punk allaperto')->assertDontSee('Concerto al chiuso');
    $this->get('/eventi?municipality=Este')->assertOk()->assertSee('Concerto punk allaperto')->assertDontSee('Concerto al chiuso');
    $this->get('/eventi?venue='.$accessibile->slug)->assertOk()->assertSee('Concerto punk allaperto')->assertDontSee('Concerto al chiuso');
    $this->get('/eventi?outdoor=1')->assertOk()->assertSee('Concerto punk allaperto')->assertDontSee('Concerto al chiuso');
    $this->get('/eventi?accessible=1')->assertOk()->assertSee('Concerto punk allaperto')->assertDontSee('Concerto al chiuso');
});

it('seleziona per famiglie le categorie dichiarate in configurazione', function (): void {
    $city = testCity();
    $famiglie = testCategory(['name' => 'Bambini e famiglie']);
    $altro = testCategory(['name' => 'Musica dal vivo']);

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $famiglie, '2026-09-06 16:00:00', event: ['title' => 'Spettacolo per bambini']);
    occurrenceAtLocal($city, $altro, '2026-09-06 22:00:00', event: ['title' => 'Concerto notturno']);

    expect(config('eventi.family_categories'))->toContain('bambini-e-famiglie');

    $this->get('/eventi?family=1')
        ->assertOk()
        ->assertSee('Spettacolo per bambini')
        ->assertDontSee('Concerto notturno');
});

/*
 * Un link vecchio o storpiato deve rendere una pagina, non un errore di
 * validazione: i valori che non si riconoscono si buttano via.
 */
it('ignora i parametri illeggibili invece di rispondere con un errore', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Evento valido']);

    $this->get('/eventi?date=pizza&price=gratuito&time=alba&sort=magia&accessible=forse&lat=nord')
        ->assertOk()
        ->assertSee('Evento valido');
});

it('mostra uno stato vuoto solo dove qualcuno ha chiesto qualcosa di preciso', function (): void {
    $city = testCity();
    testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $this->get('/eventi?q=qualcosa-che-non-esiste')
        ->assertOk()
        ->assertSee(__('events.empty.search_title'));
});

it('risponde 404 su una data inesistente e su una categoria spenta', function (): void {
    $city = testCity();
    $category = testCategory(['is_active' => false]);

    freezeLocal($city, '2026-09-05 12:00:00');

    $this->get('/eventi/2026-02-31')->assertNotFound();
    $this->get('/eventi/categoria/'.$category->slug)->assertNotFound();
});
