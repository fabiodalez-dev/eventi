<?php

declare(strict_types=1);

use App\Enums\VenueStatus;
use App\Models\Venue;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('elenca i locali approvati con il numero di date in programma', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $attivo = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Circolo Attivo']);
    $bozza = Venue::factory()->create(['city_id' => $city->getKey(), 'name' => 'Locale In Bozza', 'status' => VenueStatus::Draft]);

    occurrenceAtLocal($city, $category, '2026-09-08 21:00:00', venue: $attivo);
    occurrenceAtLocal($city, $category, '2026-09-09 21:00:00', venue: $attivo);

    $this->get('/locali')
        ->assertOk()
        ->assertSee('Circolo Attivo')
        ->assertDontSee('Locale In Bozza')
        ->assertSee(trans_choice('venues.card.upcoming', 2, ['count' => 2]));
});

it('filtra i locali per comune e per nome', function (): void {
    $city = testCity();

    Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Teatro di Este', 'municipality' => 'Este']);
    Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Club di Padova', 'municipality' => 'Padova']);

    $this->get('/locali?municipality=Este')->assertOk()->assertSee('Teatro di Este')->assertDontSee('Club di Padova');
    $this->get('/locali?q=Club')->assertOk()->assertSee('Club di Padova')->assertDontSee('Teatro di Este');
});

it('mostra prossimi eventi e archivio paginato sulla scheda del locale', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Circolo Con Storia']);

    occurrenceAtLocal($city, $category, '2026-09-08 21:00:00', venue: $venue, event: ['title' => 'Serata futura']);

    foreach (range(1, 14) as $index) {
        occurrenceAtLocal($city, $category, '2026-08-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).' 21:00:00', venue: $venue, event: [
            'title' => 'Serata passata numero '.$index,
        ]);
    }

    $first = $this->get('/locali/'.$venue->slug)->assertOk();

    $first->assertSee('Serata futura')
        ->assertSee(__('venues.detail.past_events'))
        ->assertSee('archivio=2', escape: false);

    /* L'archivio è dal più recente al più vecchio: il 14 agosto viene prima. */
    $first->assertSee('Serata passata numero 14');

    $this->get('/locali/'.$venue->slug.'?archivio=2')
        ->assertOk()
        ->assertSee('Serata passata numero 1')
        ->assertSee('Serata futura');
});

it('mostra il pulsante Segui come promessa, non come inganno', function (): void {
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

    $this->get('/locali/'.$venue->slug)
        ->assertOk()
        ->assertSee(__('common.actions.follow'))
        ->assertSee(__('venues.detail.follow_soon'));
});

it('offre le indicazioni verso entrambe le applicazioni di navigazione', function (): void {
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

    $this->get('/locali/'.$venue->slug)
        ->assertOk()
        ->assertSee('google.com/maps/dir', escape: false)
        ->assertSee('maps.apple.com', escape: false)
        /* Il terzo rimando e' a OpenStreetMap. Era il riquadro incorporato
           (`/export/embed.html`), che portava dentro la scheda il sito intero —
           barra, controlli, «Make a Donation» — e soprattutto la sua tavolozza
           chiara, che un `iframe` sottrae al foglio di stile della pagina. Ora
           la mappa e' lo stesso riquadro Leaflet di tutto il sito e questo
           resta un collegamento: chi vuole muoversi apre OpenStreetMap. */
        ->assertSee('openstreetmap.org/', escape: false);
});

it('pubblica il Place nei dati strutturati del locale', function (): void {
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Sala Prova']);

    $content = $this->get('/locali/'.$venue->slug)->assertOk()->getContent() ?: '';

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $content, $matches);

    $types = array_map(
        static fn (string $raw): mixed => json_decode($raw, true)['@type'] ?? null,
        $matches[1],
    );

    expect($types)->toContain('Place')->toContain('BreadcrumbList');
});

it('non pubblica un locale in bozza e tiene fuori dagli indici quello sospeso', function (): void {
    $city = testCity();

    $bozza = Venue::factory()->create(['city_id' => $city->getKey(), 'status' => VenueStatus::Draft]);
    $sospeso = Venue::factory()->create(['city_id' => $city->getKey(), 'status' => VenueStatus::Suspended]);

    $this->get('/locali/'.$bozza->slug)->assertNotFound();

    $this->get('/locali/'.$sospeso->slug)
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);
});
