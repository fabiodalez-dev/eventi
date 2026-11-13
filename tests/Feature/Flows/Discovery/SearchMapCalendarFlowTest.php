<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Models\City;
use App\Models\Tag;
use App\Support\EventUrl;
use Carbon\Carbon;

require_once __DIR__.'/Helpers.php';

/*
 * Gli altri modi di trovare qualcosa da fare: la ricerca libera con i suoi
 * suggerimenti, la mappa, il calendario mensile, il percorso guidato di
 * «stasera» e il feed RSS.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

it('passa dai suggerimenti alla ricerca completa e da lì alla scheda dell\'evento', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    $circolo = scopertaLocale($city, ['name' => 'Circolo Ocarina']);
    $serata = occurrenceAtLocal($city, $category, '2026-09-11 21:00:00', event: ['title' => 'Serata ocarina e flauti'], venue: $circolo);
    occurrenceAtLocal($city, $category, '2026-09-11 21:00:00', event: ['title' => 'Ocarina segreta in bozza', 'status' => EventStatus::Draft]);
    Tag::factory()->create(['name' => 'Ocarine', 'is_approved' => true]);

    /* Due lettere non bastano: i suggerimenti partono dalla terza. */
    $this->get('/cerca/suggerimenti?q=oc')->assertOk()->assertDontSee('Serata ocarina e flauti');

    $this->get('/cerca/suggerimenti?q=oca')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertSee('Serata ocarina e flauti')
        ->assertSee('Circolo Ocarina')
        ->assertDontSee('Ocarina segreta in bozza')
        ->assertDontSee('<html', escape: false);

    /* Invio: la ricerca completa raggruppa per tipo e non entra negli indici. */
    $this->get('/cerca?q=ocarina')
        ->assertOk()
        ->assertSee(__('search.groups.events'))
        ->assertSee(__('search.groups.venues'))
        ->assertSee('Serata ocarina e flauti')
        ->assertSee('Circolo Ocarina')
        ->assertSee(EventUrl::occurrence($serata), escape: false)
        ->assertDontSee('Ocarina segreta in bozza')
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);

    /* Il collegamento del risultato porta alla data giusta. */
    $this->get(EventUrl::occurrence($serata))->assertOk()->assertSee('Serata ocarina e flauti');

    /* Un input malformato è un errore di validazione, non una pagina vuota. */
    $this->getJson('/cerca/suggerimenti?q='.str_repeat('a', 121))->assertUnprocessable();
});

it('esplora la mappa: pagina, marcatori filtrati nel rettangolo e foglio del locale', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $teatro = testCategory(['name' => 'Teatro e danza']);
    $vicenza = City::factory()->padova()->create(['name' => 'Vicenza', 'slug' => 'vicenza']);

    freezeLocal($city, '2026-09-09 12:00:00');

    $centro = scopertaLocale($city, ['name' => 'Sala del Centro', 'lat' => 45.4064, 'lng' => 11.8768]);
    $teatroLocale = scopertaLocale($city, ['name' => 'Teatro del Ponte', 'lat' => 45.4100, 'lng' => 11.8800]);
    $fuori = scopertaLocale($city, ['name' => 'Rifugio in quota', 'lat' => 45.9500, 'lng' => 12.4000]);
    $estraneo = scopertaLocale($vicenza, ['lat' => 45.5500, 'lng' => 11.5500]);

    occurrenceAtLocal($city, $musica, '2026-09-09 21:00:00', event: ['title' => 'Concerto di stasera'], venue: $centro);
    occurrenceAtLocal($city, $musica, '2026-09-10 21:00:00', event: ['title' => 'Concerto di domani'], venue: $centro);
    occurrenceAtLocal($city, $teatro, '2026-09-10 21:00:00', event: ['title' => 'Prosa sul ponte'], venue: $teatroLocale);
    occurrenceAtLocal($city, $musica, '2026-09-10 21:00:00', event: ['title' => 'Concerto in alta quota'], venue: $fuori);

    /* La pagina apre su oggi; il link «tutte le date» allarga la vista. */
    $this->get('/mappa')
        ->assertOk()
        ->assertSee('Concerto di stasera')
        ->assertDontSee('Prosa sul ponte')
        ->assertSee('/mappa?all_dates=1', escape: false);

    $this->get('/mappa?all_dates=1')->assertOk()->assertSee('Prosa sul ponte');

    /* I marcatori: uno per locale, con il numero di date in quel punto. */
    $tutti = collect($this->getJson('/mappa/marcatori')->assertOk()->json('markers'));
    expect($tutti->map(fn (array $m): int => $m[0])->sort()->values()->all())
        ->toBe(collect([$centro->id, $teatroLocale->id, $fuori->id])->sort()->values()->all())
        ->and($tutti->firstWhere(0, $centro->id)[4])->toBe(2);

    /* Il rettangolo della vista taglia fuori il rifugio, la categoria il teatro. */
    $nelRettangolo = collect($this->getJson('/mappa/marcatori?bbox=11.70,45.30,12.00,45.50')->assertOk()->json('markers'))
        ->map(fn (array $m): int => $m[0])->sort()->values()->all();
    expect($nelRettangolo)->toBe(collect([$centro->id, $teatroLocale->id])->sort()->values()->all());

    $soloMusica = collect($this->getJson('/mappa/marcatori?bbox=11.70,45.30,12.00,45.50&category=musica-dal-vivo')->assertOk()->json('markers'))
        ->map(fn (array $m): int => $m[0])->all();
    expect($soloMusica)->toBe([$centro->id]);

    /* Tocco il punto: il foglio del locale è un frammento, non una pagina. */
    $this->get('/mappa/locale/'.$centro->id)
        ->assertOk()
        ->assertSee('Sala del Centro')
        ->assertSee('Concerto di domani')
        ->assertDontSee('Prosa sul ponte')
        ->assertDontSee('<!DOCTYPE html>', escape: false);

    /* Un locale di un'altra città non ha foglio qui. */
    $this->get('/mappa/locale/'.$estraneo->id)->assertNotFound();
});

it('sfoglia il calendario mensile e scende nel giorno scelto', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-09 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-20 21:00:00', event: ['title' => 'Sagra di fine settembre']);
    occurrenceAtLocal($city, $category, '2026-10-03 21:00:00', event: ['title' => 'Festa di inizio ottobre']);
    occurrenceAtLocal($city, $category, '2026-09-21 21:00:00', event: ['title' => 'Bozza nel calendario', 'status' => EventStatus::Draft]);

    $settembre = $this->get('/calendario')
        ->assertOk()
        ->assertSee('Sagra di fine settembre')
        ->assertDontSee('Festa di inizio ottobre')
        ->assertDontSee('Bozza nel calendario')
        ->assertSee(route('events.date', ['date' => '2026-09-20']), escape: false)
        ->assertSee(route('calendar.month', ['month' => '2026-10']), escape: false);

    expect(scopertaCanonico($settembre))->toBe(route('calendar.month', ['month' => '2026-09']));

    /* Il mese dopo, dal link «successivo». */
    $this->get('/calendario/2026-10')
        ->assertOk()
        ->assertSee('Festa di inizio ottobre')
        ->assertDontSee('Sagra di fine settembre')
        ->assertSee(route('events.date', ['date' => '2026-10-03']), escape: false);

    /* Il giorno: la lista di quella giornata evento. */
    $this->get('/eventi/2026-10-03')
        ->assertOk()
        ->assertSee('Festa di inizio ottobre')
        ->assertDontSee('Sagra di fine settembre');

    /* Un mese che non esiste. */
    $this->get('/calendario/2026-13')->assertNotFound();
});

it('compone un calendario personalizzato passo per passo e rifiuta categorie sconosciute', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica', 'slug' => 'musica']);
    $teatro = testCategory(['name' => 'Teatro', 'slug' => 'teatro']);

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $musica, '2026-09-06 20:00:00', event: ['title' => 'Concerto scelto']);
    occurrenceAtLocal($city, $musica, '2026-10-06 20:00:00', event: ['title' => 'Concerto lontano']);
    occurrenceAtLocal($city, $teatro, '2026-09-06 20:00:00', event: ['title' => 'Spettacolo escluso']);

    /* Passo 1: si scelgono le categorie. */
    $this->get('/calendario/personalizza')
        ->assertOk()
        ->assertSee('Musica')
        ->assertSee('categories[]', escape: false);

    /* Passo 2: l'orizzonte, con la spiegazione dell'abbonamento continuo. */
    $this->get('/calendario/personalizza?step=2&categories[]=musica')
        ->assertOk()
        ->assertSee(__('subscriptions.horizon_help'));

    /* Passo 3: l'anteprima conta solo le date che finiranno nel file. */
    $this->get('/calendario/personalizza?step=3&categories[]=musica&days=7')
        ->assertOk()
        ->assertViewHas('preview', fn ($items): bool => $items->count() === 1)
        ->assertSee('category=musica', escape: false)
        ->assertSee('days=7', escape: false);

    /* Il file personale vuole un account: chi non è dentro viene mandato ad accedere. */
    $this->get('/eventi.ics?category=musica&days=7')->assertRedirect();

    $this->getJson('/calendario/personalizza?categories[]=inesistente')->assertUnprocessable();
});

it('segue il percorso guidato di stasera fino all\'elenco filtrato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-10 17:00:00');

    $centro = scopertaLocale($city, ['municipality' => 'Padova', 'zone' => 'Centro']);
    $arcella = scopertaLocale($city, ['municipality' => 'Padova', 'zone' => 'Arcella']);

    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', venue: $centro, event: ['title' => 'Serata gratuita in centro', 'price_type' => PriceType::Free]);
    occurrenceAtLocal($city, $category, '2026-09-11 21:00:00', venue: $centro, event: ['title' => 'Serata di domani in centro', 'price_type' => PriceType::Free]);
    occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', venue: $arcella, event: ['title' => 'Serata in Arcella', 'price_type' => PriceType::Free]);

    /* La prima domanda. */
    $this->get('/stasera')->assertOk()->assertSee('La tua prossima serata.');

    /* La domanda sulle categorie, con le risposte già date in query string. */
    $this->get('/stasera?question=categories&when=tonight&municipality=Padova&zone=Centro&budget=0')
        ->assertOk()
        ->assertSee($category->name);

    /* I risultati: il sito porta all'elenco con gli stessi filtri accesi. */
    $atteso = route('events.index', [
        'date' => 'tonight', 'municipality' => 'Padova', 'zone' => 'Centro', 'budget' => '0',
        'category' => $category->slug, 'discovery' => '1',
    ]);

    $this->get('/stasera?question=results&when=tonight&municipality=Padova&zone=Centro&budget=0&categories[]='.$category->id)
        ->assertRedirect($atteso);

    $this->get($atteso)
        ->assertOk()
        ->assertSee('Serata gratuita in centro')
        ->assertDontSee('Serata di domani in centro')
        ->assertDontSee('Serata in Arcella');

    /* Una zona che non è un quartiere di Padova è un errore di validazione. */
    $this->getJson('/stasera?question=categories&municipality=Padova&zone=Quartiere-inventato')->assertUnprocessable();
});

it('offre un feed RSS valido che rispetta i filtri della lista', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $teatro = testCategory(['name' => 'Teatro e danza']);

    freezeLocal($city, '2026-09-09 12:00:00');

    occurrenceAtLocal($city, $musica, '2026-09-10 21:00:00', event: ['title' => 'Concerto & aperitivo']);
    occurrenceAtLocal($city, $teatro, '2026-09-10 21:00:00', event: ['title' => 'Monologo in cantina']);
    occurrenceAtLocal($city, $musica, '2026-09-10 22:00:00', event: ['title' => 'Concerto in bozza', 'status' => EventStatus::Draft]);

    /* La lista per categoria pubblicizza il proprio feed con lo stesso filtro. */
    $this->get('/eventi?category=musica-dal-vivo')
        ->assertOk()
        ->assertSee(route('feeds.rss', ['category' => 'musica-dal-vivo']), escape: false);

    $tutto = $this->get('/feed.rss')->assertOk()->assertHeader('content-type', 'application/rss+xml; charset=utf-8');
    $xml = simplexml_load_string((string) $tutto->getContent());

    expect($xml)->not->toBeFalse()
        ->and($xml->channel->item)->toHaveCount(2);

    $filtrato = $this->get(route('feeds.rss', ['category' => 'musica-dal-vivo']))->assertOk();
    $xml = simplexml_load_string((string) $filtrato->getContent());

    $titoli = [];
    foreach ($xml->channel->item as $item) {
        $titoli[] = (string) $item->title;
    }

    expect($titoli)->toHaveCount(1)
        ->and($titoli[0])->toContain('Concerto & aperitivo')
        ->and(implode(' ', $titoli))->not->toContain('Monologo')->not->toContain('bozza');
});
