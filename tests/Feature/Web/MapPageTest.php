<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\City;
use App\Models\Venue;
use Carbon\Carbon;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * La mappa (§11.6) e "vicino a me" (§11.7).
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function mapVenue(int $cityId, float $lat, float $lng, array $attributes = []): Venue
{
    return Venue::factory()->approved()->create([
        'city_id' => $cityId,
        'lat' => $lat,
        'lng' => $lng,
        'location' => new Point($lat, $lng, 0),
        ...$attributes,
    ]);
}

it('risponde e dichiara l\'attribuzione a OpenStreetMap, che è un obbligo di licenza', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

    $this->get('/mappa')
        ->assertOk()
        ->assertSee(__('ui.footer.osm'))
        ->assertSee('openstreetmap.org/copyright', escape: false)
        ->assertSee('opendatacommons.org/licenses/odbl', escape: false);
});

it('non chiede alcuna chiave di accesso per le tessere', function (): void {
    $tessere = config()->string('map.style_url');

    expect($tessere)
        ->not->toContain('key=')
        ->not->toContain('apikey')
        ->not->toContain('access_token')
        ->not->toContain('token=');
});

/*
 * Le due trappole in cui questa configurazione e' gia' caduta, una per test.
 */
it('punta a uno stile vettoriale scuro', function (): void {
    $tessere = config()->string('map.style_url');

    expect($tessere)->toContain('openfreemap.org')->toContain('/styles/dark');
});

it('attribuisce le tessere a chi le serve davvero', function (): void {
    /*
     * L'attribuzione e' una condizione di licenza, non un ringraziamento: deve
     * nominare il fornitore vero. Dopo un cambio di fornitore ha continuato a
     * citare quello vecchio, che e' il modo piu' silenzioso di violarla.
     *
     * La corrispondenza e' una TABELLA e non un'euristica sul dominio: la
     * prima versione ritagliava la radice dell'indirizzo e la confrontava col
     * nome mostrato, e su `services.arcgisonline.com` pretendeva di leggere
     * «services». Una tabella e' piu' rigida di proposito — cambiando
     * fornitore questo test diventa rosso e chiede di dichiarare come si
     * chiama, che e' esattamente il momento in cui bisogna fermarsi a
     * pensare all'attribuzione invece di scoprirlo sei mesi dopo.
     */
    $fornitori = [
        'arcgisonline.com' => 'Esri',
        'openstreetmap.org' => 'OpenStreetMap',
        'basemaps.cartocdn.com' => 'CARTO',
        'openfreemap.org' => 'OpenFreeMap',
    ];

    $dominio = parse_url(config()->string('map.style_url'), PHP_URL_HOST) ?? '';

    $atteso = collect($fornitori)
        ->first(fn (string $nome, string $host): bool => str_ends_with($dominio, $host));

    expect($atteso)->not->toBeNull("fornitore di tessere sconosciuto ({$dominio}): aggiungilo alla tabella e controlla che __('map.tiles') lo nomini")
        ->and(__('map.tiles'))->toBe($atteso);
});

it('resta leggibile senza JavaScript: sotto al riquadro c\'è l\'elenco', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', event: ['title' => 'Concerto in elenco']);

    $this->get('/mappa')
        ->assertOk()
        ->assertSee('Concerto in elenco')
        ->assertSee(__('map.fallback_title'));
});

it('apre su oggi e permette di passare esplicitamente a tutte le date', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', event: ['title' => 'Evento di oggi']);
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Evento di domani']);

    $this->get('/mappa')
        ->assertOk()
        ->assertSee('Evento di oggi')
        ->assertDontSee('Evento di domani')
        ->assertSee('/mappa?all_dates=1', escape: false)
        ->assertDontSee('/eventi?date=tomorrow', escape: false);

    $this->get('/mappa?all_dates=1')
        ->assertOk()
        ->assertSee('Evento di oggi')
        ->assertSee('Evento di domani');
});

it('spedisce un carico minimo: identificativi e coordinate, non gli eventi interi', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $locale = mapVenue((int) $city->getKey(), 45.4064, 11.8768);
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Titolo che non deve viaggiare'], venue: $locale);

    $risposta = $this->getJson('/mappa/marcatori?bbox=11.70,45.30,12.00,45.50')->assertOk();

    $payload = $risposta->json();

    expect($payload['markers'])->toHaveCount(1)
        ->and($payload['markers'][0][0])->toBe((int) $locale->getKey())
        ->and($payload['markers'][0][4])->toBe(1)
        ->and($payload['categories'][0])->toHaveKeys(['slug', 'name', 'color']);

    /* Il titolo, la descrizione e la locandina non stanno nel carico: arrivano
       dopo, per il solo marcatore che qualcuno tocca. */
    $risposta->assertDontSee('Titolo che non deve viaggiare');
});

it('restringe i marcatori al rettangolo chiesto', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $dentro = mapVenue((int) $city->getKey(), 45.4064, 11.8768);
    $fuori = mapVenue((int) $city->getKey(), 45.9500, 12.4000);

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $dentro);
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', venue: $fuori);

    $identificativi = collect($this->getJson('/mappa/marcatori?bbox=11.70,45.30,12.00,45.50')->json('markers'))
        ->map(static fn (array $marker): int => $marker[0])
        ->all();

    expect($identificativi)->toBe([(int) $dentro->getKey()]);
});

it('ignora un rettangolo malformato invece di rispondere con un errore', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

    $this->getJson('/mappa/marcatori?bbox=cascasse-il-mondo')
        ->assertOk()
        ->assertJsonCount(1, 'markers');
});

it('non disegna gli eventi non pubblicati', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $occorrenza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');
    $occorrenza->event->update(['status' => EventStatus::Draft]);

    $this->getJson('/mappa/marcatori')->assertOk()->assertJsonCount(0, 'markers');
});

it('condivide i filtri con la lista', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $teatro = testCategory(['name' => 'Teatro e danza']);

    freezeLocal($city, '2026-09-05 12:00:00');

    $atteso = mapVenue((int) $city->getKey(), 45.4064, 11.8768);
    occurrenceAtLocal($city, $musica, '2026-09-06 21:00:00', venue: $atteso);
    occurrenceAtLocal($city, $teatro, '2026-09-06 21:00:00', venue: mapVenue((int) $city->getKey(), 45.4100, 11.8800));

    $identificativi = collect($this->getJson('/mappa/marcatori?category='.$musica->slug)->json('markers'))
        ->map(static fn (array $marker): int => $marker[0])
        ->all();

    expect($identificativi)->toBe([(int) $atteso->getKey()]);
});

it('serve la card del foglio inferiore come HTML già disegnato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $locale = mapVenue((int) $city->getKey(), 45.4064, 11.8768, ['name' => 'Circolo di prova']);
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Serata di prova'], venue: $locale);

    $this->get('/mappa/locale/'.$locale->getKey())
        ->assertOk()
        ->assertSee('Circolo di prova')
        ->assertSee('Serata di prova')
        ->assertDontSee('<!DOCTYPE html>', escape: false);
});

it('non serve il foglio di un locale di un\'altra città', function (): void {
    $city = testCity();
    $altra = City::factory()->padova()->create(['name' => 'Vicenza', 'slug' => 'vicenza']);

    freezeLocal($city, '2026-09-05 12:00:00');

    $estraneo = mapVenue((int) $altra->getKey(), 45.5500, 11.5500);

    $this->get('/mappa/locale/'.$estraneo->getKey())->assertNotFound();
});

describe('vicino a me (§11.7)', function (): void {
    it('offre i quattro raggi e spiega perché chiede la posizione', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

        $risposta = $this->get('/mappa')->assertOk();

        $risposta->assertSee(__('map.near.title'))
            ->assertSee(__('map.near.body'));

        foreach ([1, 5, 10, 25] as $km) {
            $risposta->assertSee(__('map.near.radius', ['km' => $km]));
        }
    });

    it('non chiede la posizione all\'apertura: il pulsante è nascosto e lo scopre il JavaScript', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

        $html = $this->get('/mappa')->assertOk()->getContent();

        expect($html)->toBeString()
            ->and($html)->toContain('data-geolocate')
            /* Il pulsante nasce nascosto: senza geolocalizzazione non compare
               mai, e nessuno chiede la posizione al caricamento della pagina.
               Si verifica l'attributo `hidden` sull'elemento con
               `data-geolocate`, non le classi che porta: un'asserzione sul
               foglio di stile va rossa a ogni ritocco senza che si sia rotto
               niente. */
            ->and($html)->toMatch('/<button[^>]*data-geolocate[^>]*class="[^"]*\bhidden\b/');
    });

    it('la posizione vive nell\'indirizzo e da nessun\'altra parte', function (): void {
        $city = testCity();
        $category = testCategory();

        freezeLocal($city, '2026-09-05 12:00:00');

        $vicino = mapVenue((int) $city->getKey(), 45.4064, 11.8768);
        $lontano = mapVenue((int) $city->getKey(), 45.6500, 11.8768);

        $atteso = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'A due passi'], venue: $vicino);
        occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Lontanissimo'], venue: $lontano);

        $this->get('/eventi?lat=45.4064&lng=11.8768&radius=5')
            ->assertOk()
            ->assertSee('A due passi')
            ->assertDontSee('Lontanissimo');

        expect($atteso->fresh())->not->toBeNull();

        /* Nessuna riga nuova da nessuna parte: la posizione non si salva. */
        $this->assertDatabaseMissing('settings', ['key' => 'lat']);
    });
});
