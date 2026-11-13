<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Models\City;
use App\Models\SearchMiss;
use App\Models\Tag;
use App\Services\Search\SearchMissLog;
use Carbon\Carbon;

require_once __DIR__.'/Helpers.php';

/*
 * Flussi di scoperta sul sito: chi arriva dalla home, apre `/eventi` e
 * stringe la lista un filtro alla volta, come farebbe una persona vera.
 *
 * Il calendario di riferimento: mercoledì 9 settembre 2026. Domani è giovedì
 * 10, il fine settimana va da venerdì 11 a domenica 13 (§8: il weekend del
 * motore comprende il venerdì).
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

it('porta dalla home alla lista e poi alla categoria, con rotta parlante e query string equivalenti', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $teatro = testCategory(['name' => 'Teatro e danza']);

    freezeLocal($city, '2026-09-09 15:00:00');

    occurrenceAtLocal($city, $musica, '2026-09-09 21:00:00', event: ['title' => 'Concerto nel chiostro']);
    occurrenceAtLocal($city, $teatro, '2026-09-10 21:00:00', event: ['title' => 'Prosa in sala grande']);

    /* La home mostra la serata di oggi e porta all'elenco completo. */
    $this->get('/')
        ->assertOk()
        ->assertSee('Concerto nel chiostro')
        ->assertSee(route('events.index'), escape: false);

    /* L'elenco senza filtri ha tutto ciò che deve ancora succedere. */
    $this->get('/eventi')
        ->assertOk()
        ->assertSee('Concerto nel chiostro')
        ->assertSee('Prosa in sala grande')
        ->assertViewHas('occurrences', fn ($page): bool => $page->total() === 2);

    /* La categoria, dalla rotta parlante: è lei il canonico. */
    $parlante = $this->get('/eventi/categoria/musica-dal-vivo')
        ->assertOk()
        ->assertSee('Concerto nel chiostro')
        ->assertDontSee('Prosa in sala grande');

    expect(scopertaCanonico($parlante))->toBe(route('events.category', ['category' => 'musica-dal-vivo']));

    /* La stessa domanda scritta nella query string dichiara lo stesso canonico. */
    $query = $this->get('/eventi?category=musica-dal-vivo')
        ->assertOk()
        ->assertSee('Concerto nel chiostro')
        ->assertDontSee('Prosa in sala grande');

    expect(scopertaCanonico($query))->toBe(route('events.category', ['category' => 'musica-dal-vivo']));

    /* Una categoria spenta non ha più una pagina. */
    $teatro->update(['is_active' => false]);

    $this->get('/eventi/categoria/teatro-e-danza')->assertNotFound();
    $this->get('/eventi/categoria/categoria-inventata')->assertNotFound();
});

it('filtra per tag approvato e nega la pagina di un tag non approvato', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);

    freezeLocal($city, '2026-09-09 12:00:00');

    $jazz = Tag::factory()->create(['name' => 'Jazz manouche', 'is_approved' => true]);
    $nascosto = Tag::factory()->create(['name' => 'Etichetta in revisione', 'is_approved' => false]);

    $conTag = occurrenceAtLocal($city, $musica, '2026-09-11 21:00:00', event: ['title' => 'Quintetto alla Django']);
    $conTag->event->tags()->attach($jazz);

    $senzaTag = occurrenceAtLocal($city, $musica, '2026-09-11 22:00:00', event: ['title' => 'Cantautori in piazza']);
    $senzaTag->event->tags()->attach($nascosto);

    $pagina = $this->get('/eventi/tag/'.$jazz->slug)
        ->assertOk()
        ->assertSee('Quintetto alla Django')
        ->assertDontSee('Cantautori in piazza');

    expect(scopertaCanonico($pagina))->toBe(route('events.tag', ['tag' => $jazz->slug]));

    $this->get('/eventi?tag='.$jazz->slug)
        ->assertOk()
        ->assertSee('Quintetto alla Django')
        ->assertDontSee('Cantautori in piazza');

    /* Due filtri insieme: la rotta parlante non basta più, il canonico torna
       alla query string che li porta entrambi. */
    $combinata = $this->get('/eventi?tag='.$jazz->slug.'&category=musica-dal-vivo')->assertOk();

    expect(scopertaCanonico($combinata))
        ->toStartWith(route('events.index').'?')
        ->toContain('tag='.$jazz->slug)
        ->toContain('category=musica-dal-vivo');

    $this->get('/eventi/tag/'.$nascosto->slug)->assertNotFound();
});

it('passa alla scorciatoia gratis e ignora un prezzo storpiato invece di rispondere con un errore', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: [
        'title' => 'Proiezione a ingresso libero',
        'price_type' => PriceType::Free,
        'price_min' => null,
        'price_max' => null,
    ]);

    occurrenceAtLocal($city, $category, '2026-09-10 21:30:00', event: [
        'title' => 'Concerto con biglietto',
        'price_type' => PriceType::Ticket,
        'price_min' => 18,
        'price_max' => 18,
    ]);

    $gratis = $this->get('/eventi/gratis')
        ->assertOk()
        ->assertSee('Proiezione a ingresso libero')
        ->assertDontSee('Concerto con biglietto');

    expect(scopertaCanonico($gratis))->toBe(route('events.free'));

    $query = $this->get('/eventi?price=free')
        ->assertOk()
        ->assertSee('Proiezione a ingresso libero')
        ->assertDontSee('Concerto con biglietto');

    expect(scopertaCanonico($query))->toBe(route('events.free'));

    /* Un link vecchio con `price=pizza` vale come «nessun filtro». */
    $this->get('/eventi?price=pizza')
        ->assertOk()
        ->assertSee('Proiezione a ingresso libero')
        ->assertSee('Concerto con biglietto');
});

it('distingue oggi, domani e il fine settimana sulla giornata della città', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-09 21:00:00', event: ['title' => 'Aperitivo del mercoledì']);
    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Cineforum del giovedì']);
    occurrenceAtLocal($city, $category, '2026-09-12 21:00:00', event: ['title' => 'Festa del sabato']);
    occurrenceAtLocal($city, $category, '2026-09-15 21:00:00', event: ['title' => 'Lezione del martedì']);

    $this->get('/eventi/oggi')
        ->assertOk()
        ->assertSee('Aperitivo del mercoledì')
        ->assertDontSee('Cineforum del giovedì')
        ->assertDontSee('Festa del sabato');

    $this->get('/eventi/domani')
        ->assertOk()
        ->assertSee('Cineforum del giovedì')
        ->assertDontSee('Aperitivo del mercoledì')
        ->assertDontSee('Festa del sabato');

    $weekend = $this->get('/eventi/weekend')
        ->assertOk()
        ->assertSee('Festa del sabato')
        ->assertDontSee('Cineforum del giovedì')
        ->assertDontSee('Lezione del martedì');

    expect(scopertaCanonico($weekend))->toBe(route('events.weekend'));

    /* Le stesse finestre come parametro: stesso insieme di date. */
    $this->get('/eventi?date=weekend')
        ->assertOk()
        ->assertViewHas('occurrences', fn ($page): bool => collect($page->items())->pluck('event.title')->all() === ['Festa del sabato']);

    /* E una lista senza finestra le contiene tutte e quattro. */
    $this->get('/eventi')->assertOk()->assertViewHas('occurrences', fn ($page): bool => $page->total() === 4);
});

it('apre la pagina di un giorno e di un intervallo, e rifiuta un giorno che non esiste', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-09 21:00:00', event: ['title' => 'Aperitivo del mercoledì']);
    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Cineforum del giovedì']);
    occurrenceAtLocal($city, $category, '2026-09-12 21:00:00', event: ['title' => 'Festa del sabato']);
    occurrenceAtLocal($city, $category, '2026-09-15 21:00:00', event: ['title' => 'Lezione del martedì']);

    $giorno = $this->get('/eventi/2026-09-12')
        ->assertOk()
        ->assertSee('Festa del sabato')
        ->assertDontSee('Cineforum del giovedì')
        ->assertDontSee('Aperitivo del mercoledì');

    expect(scopertaCanonico($giorno))->toBe(route('events.date', ['date' => '2026-09-12']));

    /* `?date=` con una data puntuale porta alla stessa pagina parlante. */
    expect(scopertaCanonico($this->get('/eventi?date=2026-09-12')->assertOk()))
        ->toBe(route('events.date', ['date' => '2026-09-12']));

    /* Un intervallo da giovedì a sabato tiene fuori mercoledì e martedì. */
    $this->get('/eventi?from=2026-09-10&to=2026-09-12')
        ->assertOk()
        ->assertSee('Cineforum del giovedì')
        ->assertSee('Festa del sabato')
        ->assertDontSee('Aperitivo del mercoledì')
        ->assertDontSee('Lezione del martedì');

    /* Il 31 febbraio rispetta il formato della rotta ma non il calendario. */
    $this->get('/eventi/2026-02-31')->assertNotFound();
});

it('stringe la lista per comune e per quartiere e conta le alternative di zona', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    $arcella = scopertaLocale($city, ['municipality' => 'Padova', 'zone' => 'Arcella']);
    $portello = scopertaLocale($city, ['municipality' => 'Padova', 'zone' => 'Portello', 'lat' => 45.4100, 'lng' => 11.8900]);
    $este = scopertaLocale($city, ['municipality' => 'Este', 'zone' => 'Centro storico', 'lat' => 45.2275, 'lng' => 11.6575]);

    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Serata in Arcella'], venue: $arcella);
    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Serata al Portello'], venue: $portello);
    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Serata sotto il castello'], venue: $este);

    $this->get('/eventi?municipality=Padova')
        ->assertOk()
        ->assertSee('Serata in Arcella')
        ->assertSee('Serata al Portello')
        ->assertDontSee('Serata sotto il castello');

    $this->get('/eventi?municipality=Padova&zone=Arcella')
        ->assertOk()
        ->assertSee('Serata in Arcella')
        ->assertDontSee('Serata al Portello')
        ->assertDontSee('Serata sotto il castello')
        /* I conteggi di zona ignorano la zona scelta (si può cambiare con un
           tocco) ma tengono il comune: Portello resta un'alternativa da 1. */
        ->assertViewHas('facetCounts', fn (array $counts): bool => ($counts['zone']['Arcella'] ?? 0) === 1
            && ($counts['zone']['Portello'] ?? 0) === 1
            && ! array_key_exists('Centro storico', $counts['zone']));

    $this->get('/eventi?municipality=Este')
        ->assertOk()
        ->assertSee('Serata sotto il castello')
        ->assertDontSee('Serata in Arcella');
});

it('filtra per distanza dalla posizione e scarta coordinate non numeriche', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    /* Uno in centro a Padova, l'altro a Cittadella: circa 27 km in linea d'aria. */
    $vicino = scopertaLocale($city, ['lat' => 45.4070, 'lng' => 11.8770]);
    $lontano = scopertaLocale($city, ['lat' => 45.6480, 'lng' => 11.7830]);

    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Concerto sotto casa'], venue: $vicino);
    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Concerto dentro le mura'], venue: $lontano);

    $this->get('/eventi?lat=45.4064&lng=11.8768&radius=5')
        ->assertOk()
        ->assertSee('Concerto sotto casa')
        ->assertDontSee('Concerto dentro le mura')
        ->assertViewHas('facetCounts', fn (array $counts): bool => ($counts['radius']['25'] ?? null) === 1
            && ($counts['radius']['1'] ?? null) === 1);

    $this->get('/eventi?lat=45.4064&lng=11.8768&radius=50')
        ->assertOk()
        ->assertSee('Concerto sotto casa')
        ->assertSee('Concerto dentro le mura');

    /* Una latitudine che non è un numero viene tolta: niente posizione, tutto visibile. */
    $this->get('/eventi?lat=abc&lng=11.8768&radius=5')
        ->assertOk()
        ->assertSee('Concerto sotto casa')
        ->assertSee('Concerto dentro le mura');
});

it('cerca nel testo, toglie la ricerca dagli indici e registra le ricerche andate a vuoto', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Notte di fisarmonica']);
    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Quartetto di archi']);

    $this->get('/eventi?q=fisarmonica')
        ->assertOk()
        ->assertSee('Notte di fisarmonica')
        ->assertDontSee('Quartetto di archi')
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);

    expect(SearchMiss::query()->count())->toBe(0);

    /* Una ricerca senza risultati finisce nel registro della redazione,
       e una seconda uguale ne aumenta il conteggio invece di duplicarla. */
    $this->get('/eventi?q=Zampogna')->assertOk()->assertDontSee('Notte di fisarmonica');
    $this->get('/eventi?q=zampogna')->assertOk();

    expect(SearchMiss::query()->count())->toBe(1)
        ->and(app(SearchMissLog::class)->count($city, 'zampogna'))->toBe(2);
});

it('non mostra bozze, proposte in attesa, eventi annullati, rifiutati, archiviati o passati', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Evento pubblicato']);
    $bozza = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Evento in bozza', 'status' => EventStatus::Draft]);
    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Evento in attesa', 'status' => EventStatus::Pending]);
    $annullato = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Evento annullato', 'status' => EventStatus::Cancelled]);
    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: ['title' => 'Evento rifiutato', 'status' => EventStatus::Rejected]);
    $archiviato = occurrenceAtLocal($city, $category, '2026-09-01 21:00:00', event: ['title' => 'Evento archiviato', 'status' => EventStatus::Archived]);
    occurrenceAtLocal($city, $category, '2026-09-02 21:00:00', event: ['title' => 'Evento già passato']);

    $nascosti = ['Evento in bozza', 'Evento in attesa', 'Evento annullato', 'Evento rifiutato', 'Evento archiviato', 'Evento già passato'];

    $lista = $this->get('/eventi')->assertOk()->assertSee('Evento pubblicato');
    foreach ($nascosti as $titolo) {
        $lista->assertDontSee($titolo);
    }

    /* L'API risponde con lo stesso insieme. */
    expect(scopertaTitoli($this->getJson('/api/v1/events')->assertOk()->json('data')))->toBe(['Evento pubblicato']);

    /* Anche la mappa disegna un solo punto. */
    $this->getJson('/mappa/marcatori')->assertOk()->assertJsonCount(1, 'markers');

    /* Le schede: bozza e annullato non esistono per il pubblico, l'archivio sì. */
    $this->get('/eventi/'.$bozza->event->slug)->assertNotFound();
    $this->get('/eventi/'.$annullato->event->slug)->assertNotFound();
    $this->getJson('/api/v1/events/'.$bozza->event->slug)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    $this->get('/eventi/'.$archiviato->event->slug)->assertOk()->assertSee('Evento archiviato');
});

it('serve le liste con il prefisso della città senza mescolare le città', function (): void {
    $padova = testCity();
    $category = testCategory(['name' => 'Musica dal vivo']);
    $vicenza = City::factory()->padova()->create(['name' => 'Vicenza', 'slug' => 'vicenza']);

    freezeLocal($padova, '2026-09-09 12:00:00');

    $padovana = occurrenceAtLocal($padova, $category, '2026-09-10 21:00:00', event: ['title' => 'Serata padovana']);
    $vicentina = occurrenceAtLocal($vicenza, $category, '2026-09-10 21:00:00', event: ['title' => 'Serata vicentina']);

    /* Senza prefisso vale la città predefinita. */
    $this->get('/eventi')->assertOk()->assertSee('Serata padovana')->assertDontSee('Serata vicentina');
    $this->get('/padova/eventi')->assertOk()->assertSee('Serata padovana')->assertDontSee('Serata vicentina');

    /* Con il prefisso la città la sceglie l'indirizzo, scorciatoie comprese. */
    $this->get('/vicenza/eventi')->assertOk()->assertSee('Serata vicentina')->assertDontSee('Serata padovana');
    $this->get('/vicenza/eventi/domani')->assertOk()->assertSee('Serata vicentina')->assertDontSee('Serata padovana');
    $this->get('/vicenza/eventi/categoria/musica-dal-vivo')->assertOk()->assertSee('Serata vicentina')->assertDontSee('Serata padovana');

    /* La scheda di un evento di un'altra città non si apre sotto il prefisso sbagliato. */
    $this->get('/vicenza/eventi/'.$vicentina->event->slug)->assertOk()->assertSee('Serata vicentina');
    $this->get('/vicenza/eventi/'.$padovana->event->slug)->assertNotFound();

    /* E una città sconosciuta non esiste. */
    $this->get('/atlantide/eventi')->assertNotFound();
});
