<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\Organizer;
use App\Models\Page;
use App\Models\Tag;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Helpers.php';

/*
 * L'app mobile che scopre gli eventi solo attraverso l'API v1: dall'avvio
 * (config, città, home) ai filtri, alle schede, alla mappa, alla ricerca, alla
 * sincronizzazione e al meteo. Il calendario di riferimento è lo stesso del
 * sito: mercoledì 9 settembre 2026.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

it('avvia l\'app: configurazione, città disponibili e home con sezioni e numeri', function (): void {
    $city = testCity();
    $category = testCategory(['name' => 'Musica dal vivo']);
    City::factory()->create(['name' => 'Verona', 'slug' => 'verona', 'is_active' => false]);

    freezeLocal($city, '2026-09-09 15:00:00');

    $stasera = occurrenceAtLocal($city, $category, '2026-09-09 21:00:00', event: ['title' => 'Concerto di stasera']);
    $sabato = occurrenceAtLocal($city, $category, '2026-09-12 21:00:00', event: ['title' => 'Festa del sabato']);
    occurrenceAtLocal($city, $category, '2026-09-12 22:00:00', event: ['title' => 'Bozza invisibile', 'status' => EventStatus::Draft]);

    $config = $this->getJson('/api/v1/config')->assertOk();
    expect($config->json('data.api_version'))->toBe('v1')
        ->and(array_column($config->json('data.categories'), 'slug'))->toContain('musica-dal-vivo')
        ->and(array_column($config->json('data.cities'), 'slug'))->toBe([$city->slug]);

    $citta = $this->getJson('/api/v1/cities')->assertOk()->json('data');
    expect(array_column($citta, 'slug'))->toBe([$city->slug]);

    $this->getJson('/api/v1/cities/'.$city->slug)->assertOk()->assertJsonPath('data.upcoming_occurrences', 2);
    $this->getJson('/api/v1/cities/verona')->assertNotFound()->assertJsonPath('error.code', 'CITY_NOT_FOUND');

    $home = $this->getJson('/api/v1/home')->assertOk();

    expect($home->json('data.city'))->toBe($city->slug)
        ->and(collect($home->json('data.sections.tonight'))->pluck('occurrence_id')->all())->toBe([(int) $stasera->id])
        ->and(collect($home->json('data.sections.weekend'))->pluck('occurrence_id')->all())->toBe([(int) $sabato->id])
        ->and($home->json('data.stats.upcoming'))->toBe(2)
        ->and($home->json('data.stats.categories'))->toBe(1)
        ->and($home->json('data.map.markers'))->toHaveCount(2);

    /* Il titolo della bozza non viaggia in nessuna sezione. */
    expect((string) $home->getContent())->not->toContain('Bozza invisibile');
});

it('filtra gli eventi dell\'app con tutti i parametri e ne conta le faccette', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $teatro = testCategory(['name' => 'Teatro e danza']);

    freezeLocal($city, '2026-09-09 12:00:00');

    $jazz = Tag::factory()->create(['name' => 'Jazz manouche', 'is_approved' => true]);

    $arcella = scopertaLocale($city, ['municipality' => 'Padova', 'zone' => 'Arcella', 'lat' => 45.4070, 'lng' => 11.8770]);
    $portello = scopertaLocale($city, ['municipality' => 'Padova', 'zone' => 'Portello', 'lat' => 45.4100, 'lng' => 11.8900]);
    $este = scopertaLocale($city, ['municipality' => 'Este', 'zone' => 'Centro storico', 'lat' => 45.2275, 'lng' => 11.6575]);

    $a = occurrenceAtLocal($city, $musica, '2026-09-09 21:00:00', venue: $arcella, event: [
        'title' => 'Jazz in Arcella', 'price_type' => PriceType::Free, 'price_min' => null, 'price_max' => null,
    ]);
    $a->event->tags()->attach($jazz);
    occurrenceAtLocal($city, $teatro, '2026-09-10 21:00:00', venue: $este, event: [
        'title' => 'Commedia a Este', 'price_type' => PriceType::Ticket, 'price_min' => 15, 'price_max' => 15,
    ]);
    occurrenceAtLocal($city, $musica, '2026-09-12 21:00:00', venue: $portello, event: [
        'title' => 'Notte di fisarmonica', 'price_type' => PriceType::Ticket, 'price_min' => 25, 'price_max' => 25,
    ]);

    $titoli = fn (string $query): array => scopertaTitoli($this->getJson('/api/v1/events?'.$query)->assertOk()->json('data'));

    expect($titoli(''))->toBe(['Jazz in Arcella', 'Commedia a Este', 'Notte di fisarmonica'])
        ->and($titoli('categories=musica-dal-vivo'))->toBe(['Jazz in Arcella', 'Notte di fisarmonica'])
        ->and($titoli('categories[]=teatro-e-danza'))->toBe(['Commedia a Este'])
        ->and($titoli('tags='.$jazz->slug))->toBe(['Jazz in Arcella'])
        ->and($titoli('price=free'))->toBe(['Jazz in Arcella'])
        ->and($titoli('price=paid'))->toBe(['Commedia a Este', 'Notte di fisarmonica'])
        ->and($titoli('preset=tomorrow'))->toBe(['Commedia a Este'])
        ->and($titoli('date=2026-09-12'))->toBe(['Notte di fisarmonica'])
        ->and($titoli('from=2026-09-10&to=2026-09-12'))->toBe(['Commedia a Este', 'Notte di fisarmonica'])
        ->and($titoli('zone=Arcella'))->toBe(['Jazz in Arcella'])
        ->and($titoli('municipality=Este'))->toBe(['Commedia a Este'])
        ->and($titoli('venue='.$portello->slug))->toBe(['Notte di fisarmonica'])
        ->and($titoli('q=fisarmonica'))->toBe(['Notte di fisarmonica'])
        ->and($titoli('near=45.4064,11.8768&radius_km=3&sort=distance'))->toBe(['Jazz in Arcella', 'Notte di fisarmonica'])
        ->and($titoli('categories=musica-dal-vivo&preset=weekend'))->toBe(['Notte di fisarmonica']);

    /* Le faccette contano le alternative sullo stesso insieme. */
    $faccette = $this->getJson('/api/v1/events/facets?municipality=Padova')->assertOk()->json('data');
    expect($faccette['category'])->toBe(['musica-dal-vivo' => 2])
        ->and($faccette['zone'])->toMatchArray(['Arcella' => 1, 'Portello' => 1])
        ->and($faccette['price']['free'])->toBe(1);

    /* Parametri invalidi: l'API, a differenza del sito, li rifiuta. */
    $this->getJson('/api/v1/events?price=pizza')->assertUnprocessable();
    $this->getJson('/api/v1/events?bbox=1,2,3')->assertUnprocessable();
    $this->getJson('/api/v1/events?from=2026-09-12&to=2026-09-10')->assertUnprocessable();
});

it('apre la scheda di un evento con le sue date, i simili e la singola data', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $teatro = testCategory(['name' => 'Teatro e danza']);

    freezeLocal($city, '2026-09-09 12:00:00');

    $prima = occurrenceAtLocal($city, $musica, '2026-09-10 21:00:00', event: ['title' => 'Rassegna d\'autunno']);
    $event = $prima->event;
    $seconda = $event->occurrences()->create([
        'starts_at' => localInstant($city, '2026-09-17 21:00:00')->utc(),
        'ends_at' => null,
        'is_all_day' => false,
    ])->fresh();
    occurrenceAtLocal($city, $musica, '2026-09-11 21:00:00', event: ['title' => 'Concerto simile']);
    occurrenceAtLocal($city, $teatro, '2026-09-11 21:00:00', event: ['title' => 'Prosa di altra categoria']);
    $bozza = occurrenceAtLocal($city, $musica, '2026-09-11 22:00:00', event: ['title' => 'Bozza di musica', 'status' => EventStatus::Draft]);

    $scheda = $this->getJson('/api/v1/events/'.$event->slug)->assertOk();
    expect($scheda->json('data.title'))->toBe('Rassegna d\'autunno')
        ->and(collect($scheda->json('data.occurrences'))->pluck('occurrence_id')->all())->toBe([(int) $prima->id, (int) $seconda->id])
        ->and($scheda->json('data.occurrences.0.starts_at'))->toBe('2026-09-10T21:00:00+02:00');

    $date = $this->getJson('/api/v1/events/'.$event->slug.'/occurrences')->assertOk();
    expect($date->json('data'))->toHaveCount(2)
        ->and($date->json('meta.has_more'))->toBeFalse();

    expect(scopertaTitoli($this->getJson('/api/v1/events/'.$event->slug.'/similar')->assertOk()->json('data')))
        ->toBe(['Concerto simile']);

    $this->getJson('/api/v1/events/'.$event->slug.'/dates/'.$seconda->url_number)
        ->assertOk()
        ->assertJsonPath('data.occurrence_id', (int) $seconda->id)
        ->assertJsonPath('data.starts_at', '2026-09-17T21:00:00+02:00');

    $this->getJson('/api/v1/occurrences/'.$prima->id)
        ->assertOk()
        ->assertJsonPath('data.title', 'Rassegna d\'autunno')
        ->assertJsonPath('data.business_date', '2026-09-10');

    /* La bozza non esiste per l'app, in nessuna delle sue forme. */
    $this->getJson('/api/v1/events/'.$bozza->event->slug)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    $this->getJson('/api/v1/events/'.$bozza->event->slug.'/occurrences')->assertNotFound();
    $this->getJson('/api/v1/events/'.$bozza->event->slug.'/similar')->assertNotFound();
    $this->getJson('/api/v1/events/'.$bozza->event->slug.'/dates/'.$bozza->url_number)->assertNotFound();
    $this->getJson('/api/v1/occurrences/'.$bozza->id)->assertNotFound();
    $this->getJson('/api/v1/events/'.$event->slug.'/dates/9999')->assertNotFound();
});

it('legge il calendario del mese e i marcatori della mappa con rettangolo, finestra e limite', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    $centro = scopertaLocale($city, ['lat' => 45.4064, 'lng' => 11.8768]);
    $lontano = scopertaLocale($city, ['lat' => 45.6480, 'lng' => 11.7830]);

    $oggi = occurrenceAtLocal($city, $category, '2026-09-09 21:00:00', event: ['title' => 'Stasera in centro'], venue: $centro);
    occurrenceAtLocal($city, $category, '2026-09-20 21:00:00', event: ['title' => 'Domenica in centro'], venue: $centro);
    occurrenceAtLocal($city, $category, '2026-09-20 22:00:00', event: ['title' => 'Domenica fuori porta'], venue: $lontano);
    occurrenceAtLocal($city, $category, '2026-10-02 21:00:00', event: ['title' => 'Ottobre in centro'], venue: $centro);

    $settembre = $this->getJson('/api/v1/calendar?month=2026-09')->assertOk();
    $giorni = collect($settembre->json('data'))->keyBy('business_date');

    expect($settembre->json('meta.month'))->toBe('2026-09')
        ->and($settembre->json('meta.total'))->toBe(3)
        ->and($giorni['2026-09-20']['count'])->toBe(2)
        ->and($giorni['2026-09-20']['titles'])->toContain('Domenica in centro')
        ->and($giorni->has('2026-10-02'))->toBeFalse();

    $this->getJson('/api/v1/calendar?month=2026-10')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/calendar?month=settembre')->assertUnprocessable();

    /* La mappa: tutte le date future, poi solo oggi, poi solo il rettangolo. */
    expect($this->getJson('/api/v1/map/occurrences')->assertOk()->json('data'))->toHaveCount(4);

    $soloOggi = $this->getJson('/api/v1/map/occurrences?preset=today')->assertOk()->json('data');
    expect(array_column($soloOggi, 'id'))->toBe([(int) $oggi->id])
        ->and($soloOggi[0])->toHaveKeys(['id', 'event_id', 'lat', 'lng', 'category_id', 'title', 'starts_at']);

    $rettangolo = $this->getJson('/api/v1/map/occurrences?bbox=11.80,45.35,11.95,45.45')->assertOk()->json('data');
    expect(array_column($rettangolo, 'title'))->not->toContain('Domenica fuori porta')->toHaveCount(3);

    /* Il limite taglia e lo dichiara. */
    $this->getJson('/api/v1/map/occurrences?limit=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.truncated', true);

    $this->getJson('/api/v1/map/occurrences?bbox=12,45,11,46')->assertUnprocessable();
});

it('cerca dall\'app e naviga categorie e tag con i loro conteggi', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $spenta = testCategory(['name' => 'Categoria spenta', 'is_active' => false]);

    freezeLocal($city, '2026-09-09 12:00:00');

    $circolo = scopertaLocale($city, ['name' => 'Circolo della Marimba']);
    $organizzatore = Organizer::create(['name' => 'Amici della Marimba', 'city_id' => $city->id, 'is_active' => true]);
    $tag = Tag::factory()->create(['name' => 'Marimba', 'is_approved' => true, 'usage_count' => 3]);
    $nascosto = Tag::factory()->create(['name' => 'Tag non approvato', 'is_approved' => false]);

    $serata = occurrenceAtLocal($city, $musica, '2026-09-10 21:00:00', venue: $circolo, event: [
        'title' => 'Marimba sotto le stelle', 'organizer_id' => $organizzatore->id,
    ]);
    $serata->event->tags()->attach($tag);
    occurrenceAtLocal($city, $musica, '2026-09-11 21:00:00', event: ['title' => 'Concerto senza marimba nel titolo']);

    $risultati = $this->getJson('/api/v1/search?q=Marimba')->assertOk();
    expect(collect($risultati->json('data.events'))->pluck('occurrence_id')->all())->toContain((int) $serata->id)
        ->and(collect($risultati->json('data.venues'))->pluck('slug')->all())->toBe([$circolo->slug])
        ->and(collect($risultati->json('data.organizers'))->pluck('slug')->all())->toBe([$organizzatore->slug])
        ->and(collect($risultati->json('data.tags'))->pluck('slug')->all())->toBe([$tag->slug])
        ->and($risultati->json('meta.query'))->toBe('Marimba');

    $this->getJson('/api/v1/search?q=m')->assertUnprocessable();

    /* Categorie: solo le accese, con quante date ha ciascuna. */
    $categorie = collect($this->getJson('/api/v1/categories')->assertOk()->json('data'))->keyBy('slug');
    expect($categorie->has('musica-dal-vivo'))->toBeTrue()
        ->and($categorie->has($spenta->slug))->toBeFalse()
        ->and($categorie['musica-dal-vivo']['upcoming_count'])->toBe(2);

    $this->getJson('/api/v1/categories/musica-dal-vivo')->assertOk()->assertJsonPath('data.upcoming_count', 2);
    $this->getJson('/api/v1/categories/'.$spenta->slug)->assertNotFound();

    /* Tag: solo gli approvati; la scheda conta le date con quel tag. */
    $tags = collect($this->getJson('/api/v1/tags')->assertOk()->json('data'))->pluck('slug');
    expect($tags)->toContain($tag->slug)->not->toContain($nascosto->slug);

    $this->getJson('/api/v1/tags/'.$tag->slug)->assertOk()->assertJsonPath('data.upcoming_count', 1);
    $this->getJson('/api/v1/tags/'.$nascosto->slug)->assertNotFound();
});

it('scorre locali e organizzatori dall\'app, con programmazione e archivio', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    $osteria = scopertaLocale($city, ['name' => 'Osteria dei Musici', 'municipality' => 'Este']);
    $club = scopertaLocale($city, ['name' => 'Club Selvatico', 'municipality' => 'Padova']);
    $bozza = Venue::factory()->create(['city_id' => $city->id, 'name' => 'Locale in bozza', 'status' => VenueStatus::Draft]);
    $collettivo = Organizer::create(['name' => 'Collettivo Portello Sonoro', 'city_id' => $city->id, 'is_active' => true]);

    $futura = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Musica popolare in osteria', 'organizer_id' => $collettivo->id], venue: $osteria);
    $passata = occurrenceAtLocal($city, $category, '2026-08-20 21:00:00', event: ['title' => 'Serata passata in osteria'], venue: $osteria);
    occurrenceAtLocal($city, $category, '2026-09-11 23:00:00', event: ['title' => 'Notte elettronica al club'], venue: $club);

    $locali = collect($this->getJson('/api/v1/venues')->assertOk()->json('data'))->keyBy('slug');
    expect($locali->keys()->sort()->values()->all())->toBe(collect([$osteria->slug, $club->slug])->sort()->values()->all())
        ->and($locali[$osteria->slug]['upcoming_occurrences'])->toBe(1)
        ->and($locali->has($bozza->slug))->toBeFalse();

    expect(collect($this->getJson('/api/v1/venues?municipality=Este')->assertOk()->json('data'))->pluck('slug')->all())
        ->toBe([$osteria->slug]);

    $this->getJson('/api/v1/venues/'.$osteria->slug)
        ->assertOk()
        ->assertJsonPath('data.name', 'Osteria dei Musici')
        ->assertJsonPath('data.upcoming_occurrences', 1);

    expect(collect($this->getJson('/api/v1/venues/'.$osteria->slug.'/events')->assertOk()->json('data'))->pluck('occurrence_id')->all())
        ->toBe([(int) $futura->id])
        ->and(collect($this->getJson('/api/v1/venues/'.$osteria->slug.'/past')->assertOk()->json('data'))->pluck('occurrence_id')->all())
        ->toBe([(int) $passata->id]);

    $this->getJson('/api/v1/venues/'.$bozza->slug)->assertNotFound();
    $this->getJson('/api/v1/venues/'.$bozza->slug.'/events')->assertNotFound();

    /* Organizzatori: elenco e scheda con le date in arrivo. */
    expect(collect($this->getJson('/api/v1/organizers')->assertOk()->json('data'))->pluck('slug')->all())->toBe([$collettivo->slug]);

    $scheda = $this->getJson('/api/v1/organizers/'.$collettivo->slug)->assertOk();
    expect($scheda->json('data.name'))->toBe('Collettivo Portello Sonoro')
        ->and(collect($scheda->json('data.events'))->pluck('occurrence_id')->all())->toBe([(int) $futura->id]);

    $this->getJson('/api/v1/organizers/organizzatore-mai-esistito')->assertNotFound();
});

it('legge aree e statistiche e sincronizza l\'agenda ricevendo le date ritirate', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    $padova = scopertaLocale($city, ['municipality' => 'Padova']);
    $este = scopertaLocale($city, ['municipality' => 'Este', 'lat' => 45.2275, 'lng' => 11.6575]);
    $vuoto = scopertaLocale($city, ['municipality' => 'Monselice', 'lat' => 45.2420, 'lng' => 11.7500]);

    /* Tre date domani: è la soglia con cui un giorno conta come «coperto». */
    $ritirata = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Prima di domani'], venue: $padova);
    occurrenceAtLocal($city, $category, '2026-09-10 21:30:00', event: ['title' => 'Seconda di domani'], venue: $padova);
    occurrenceAtLocal($city, $category, '2026-09-10 22:00:00', event: ['title' => 'Terza di domani'], venue: $este);
    occurrenceAtLocal($city, $category, '2026-09-09 21:00:00', event: ['title' => 'Stasera a Padova'], venue: $padova);
    occurrenceAtLocal($city, $category, '2026-08-01 21:00:00', event: ['title' => 'Serata di agosto'], venue: $vuoto);

    $aree = collect($this->getJson('/api/v1/areas')->assertOk()->json('data'))->pluck('upcoming_count', 'name');
    expect($aree->all())->toBe(['Padova' => 3, 'Este' => 1]);

    $stats = $this->getJson('/api/v1/stats')->assertOk()->json('data');
    expect($stats)->toMatchArray([
        'city' => $city->slug,
        'upcoming_occurrences' => 4,
        'today' => 1,
        'tonight' => 1,
        'covered_days_next_14' => 1,
    ]);

    /* Primo sync: la finestra dei prossimi giorni, nessuna data ritirata. */
    $primo = $this->getJson('/api/v1/sync?days=30')->assertOk();
    expect($primo->json('data'))->toHaveCount(4)
        ->and($primo->json('meta.deleted_ids'))->toBe([])
        ->and($primo->json('meta.window_days'))->toBe(30);

    $since = now('UTC')->subMinute()->toIso8601String();

    /* La redazione rimette in bozza un evento: il sync successivo lo ritira. */
    $ritirata->event->update(['status' => EventStatus::Draft]);

    $secondo = $this->getJson('/api/v1/sync?'.http_build_query(['since' => $since, 'days' => 30]))->assertOk();
    expect($secondo->json('meta.deleted_ids'))->toBe([(int) $ritirata->id])
        ->and(collect($secondo->json('data'))->pluck('occurrence_id')->all())->not->toContain((int) $ritirata->id);

    $this->getJson('/api/v1/sync?days=999')->assertUnprocessable();
});

it('chiede all\'app la serata guidata, il meteo della data e le pagine informative', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 17:00:00');

    $centro = scopertaLocale($city, ['municipality' => 'Padova', 'zone' => 'Centro', 'lat' => 45.4064, 'lng' => 11.8768]);
    $stasera = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', venue: $centro, event: ['title' => 'Serata gratuita in centro', 'price_type' => PriceType::Free]);
    occurrenceAtLocal($city, $category, '2026-09-11 21:00:00', venue: $centro, event: ['title' => 'Serata di domani', 'price_type' => PriceType::Free]);

    /* «Stasera»: senza risposte nessun risultato, con le risposte la serata giusta. */
    $this->getJson('/api/v1/tonight')->assertOk()->assertJsonCount(0, 'data.results');

    $risposta = $this->getJson('/api/v1/tonight?step=3&zone=Centro&budget=0')->assertOk();
    expect(collect($risposta->json('data.results'))->pluck('occurrence.occurrence_id')->all())->toBe([(int) $stasera->id])
        ->and($risposta->json('data.results.0.reasons'))->not->toBeEmpty();

    $this->getJson('/api/v1/tonight?step=4')->assertUnprocessable();

    /* Il meteo della data: una sola chiamata al fornitore, poi la cache. */
    Http::fake(['api.open-meteo.com/*' => Http::response(['daily' => [
        'time' => ['2026-09-10'], 'weather_code' => [95], 'temperature_2m_min' => [14], 'temperature_2m_max' => [24],
        'precipitation_probability_max' => [70], 'wind_speed_10m_max' => [12],
    ]])]);

    $this->getJson('/api/v1/occurrences/'.$stasera->id.'/weather')
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.date', '2026-09-10')
        ->assertJsonPath('data.icon', 'storm');
    $this->getJson('/api/v1/occurrences/'.$stasera->id.'/weather')->assertOk()->assertJsonPath('data.icon', 'storm');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['latitude'] === 45.4064 && $request['timezone'] === 'Europe/Rome');

    $this->getJson('/api/v1/occurrences/999999/weather')->assertNotFound();

    /* Le pagine informative: solo quelle pubblicate. */
    Page::factory()->create(['slug' => 'chi-siamo', 'title' => 'Chi siamo', 'is_published' => true, 'body' => "# Chi siamo\n\nUna piattaforma di eventi locali."]);
    Page::factory()->create(['slug' => 'pagina-bozza', 'title' => 'Pagina in bozza', 'is_published' => false]);

    $pagine = collect($this->getJson('/api/v1/pages')->assertOk()->json('data'))->pluck('slug');
    expect($pagine)->toContain('chi-siamo')->not->toContain('pagina-bozza');

    $pagina = $this->getJson('/api/v1/pages/chi-siamo')->assertOk()->json('data');
    expect($pagina['title'])->toBe('Chi siamo')
        ->and($pagina['body_html'])->toContain('<h1')->toContain('Una piattaforma di eventi locali.');

    $this->getJson('/api/v1/pages/pagina-bozza')->assertNotFound();
});

it('rivalida la lista con ETag dopo una nuova pubblicazione e sceglie la città dal parametro', function (): void {
    $padova = testCity();
    $category = testCategory();
    $vicenza = City::factory()->padova()->create(['name' => 'Vicenza', 'slug' => 'vicenza']);

    freezeLocal($padova, '2026-09-09 12:00:00');

    occurrenceAtLocal($padova, $category, '2026-09-10 21:00:00', event: ['title' => 'Serata padovana']);
    occurrenceAtLocal($vicenza, $category, '2026-09-10 21:00:00', event: ['title' => 'Serata vicentina']);

    /* L'app conserva l'ETag e chiede solo se è cambiato qualcosa. */
    $prima = $this->getJson('/api/v1/events')->assertOk();
    $etag = (string) $prima->headers->get('ETag');

    expect(scopertaTitoli($prima->json('data')))->toBe(['Serata padovana'])
        ->and($etag)->not->toBe('')
        ->and((string) $prima->headers->get('Cache-Control'))->toContain('public');

    $this->getJson('/api/v1/events', ['If-None-Match' => $etag])->assertStatus(304);

    /* Esce un nuovo evento: la stessa domanda ha una risposta diversa. */
    occurrenceAtLocal($padova, $category, '2026-09-11 21:00:00', event: ['title' => 'Nuova serata padovana']);

    $dopo = $this->getJson('/api/v1/events', ['If-None-Match' => $etag])->assertOk();

    expect($dopo->headers->get('ETag'))->not->toBe($etag)
        ->and(scopertaTitoli($dopo->json('data')))->toBe(['Serata padovana', 'Nuova serata padovana']);

    /*
     * La città scelta dal parametro. Va per ultima: `CurrentCity` è un
     * servizio `scoped`, e il client di prova non lo azzera fra una richiesta
     * e l'altra come fa un processo nuovo in produzione; dopo `?city=vicenza`
     * una richiesta senza parametro resterebbe su Vicenza solo qui.
     */
    expect(scopertaTitoli($this->getJson('/api/v1/events?city=padova')->assertOk()->json('data')))->toBe(['Serata padovana', 'Nuova serata padovana'])
        ->and(scopertaTitoli($this->getJson('/api/v1/events?city=vicenza')->assertOk()->json('data')))->toBe(['Serata vicentina']);

    $this->getJson('/api/v1/events?city=atlantide')->assertNotFound()->assertJsonPath('error.code', 'CITY_NOT_FOUND');
});
