<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Redirect;
use App\Models\Tag;
use App\Models\Venue;
use App\Support\Redirect\RegistroRedirect;

/**
 * Gli indirizzi che non esistono più.
 *
 * Il guasto che questi test difendono non si vede da nessuna parte finché non
 * è tardi: `Event`, `Venue`, `Category`, `Tag` e `City` rigenerano lo slug a
 * ogni salvataggio — nessuno di loro ha `doNotGenerateSlugsOnUpdate()`, che
 * solo `Page` ha — quindi correggere un refuso in un titolo manda in 404 ogni
 * link condiviso, ogni pagina indicizzata e ogni QR già stampato. Chi rinomina
 * vede la pagina nuova e non si accorge di niente.
 */
describe('una rinomina non lascia indietro un 404', function (): void {
    it('manda il vecchio indirizzo di un evento sul nuovo', function (): void {
        $city = testCity();
        $category = testCategory();

        $event = Event::factory()->published()->create([
            'city_id' => $city->id,
            'category_id' => $category->id,
            'title' => 'Concerto di mezza estate',
        ]);

        $vecchio = $event->slug;

        $event->update(['title' => 'Concerto di fine estate', 'slug' => 'concerto-di-fine-estate']);
        $event->refresh();

        expect($event->slug)->not->toBe($vecchio);

        $this->get('/eventi/'.$vecchio)->assertRedirect('/eventi/'.$event->slug);
    });

    /*
     * Le rotte pubbliche sono registrate due volte, nude e sotto `/{city}`
     * (§11.1). Nella tabella il prefisso non c'è: chi legge lo stacca e lo
     * rimette com'era. Senza, servirebbero due righe per ogni rinomina — e
     * prima o poi ne resterebbe una sola.
     */
    it('vale anche sotto il prefisso della citta', function (): void {
        $city = testCity();
        $category = testCategory();

        $event = Event::factory()->published()->create([
            'city_id' => $city->id,
            'category_id' => $category->id,
            'title' => 'Rassegna del giovedi',
        ]);

        $vecchio = $event->slug;
        $event->update(['title' => 'Rassegna del venerdi', 'slug' => 'rassegna-del-venerdi']);
        $event->refresh();

        $this->get('/'.$city->slug.'/eventi/'.$vecchio)
            ->assertRedirect('/'.$city->slug.'/eventi/'.$event->slug);
    });

    it('segue il locale, il tag e la categoria', function (): void {
        $city = testCity();

        $venue = Venue::factory()->approved()->create(['city_id' => $city->id, 'name' => 'Sala Grande']);
        $vecchioLocale = $venue->slug;
        $venue->update(['name' => 'Sala Piccola']);
        $venue->refresh();

        $tag = Tag::factory()->create(['name' => 'Musica classica']);
        $vecchioTag = $tag->slug;
        $tag->update(['name' => 'Musica sinfonica']);
        $tag->refresh();

        $category = Category::factory()->create(['name' => 'Teatro ragazzi']);
        $vecchiaCategoria = $category->slug;
        $category->update(['name' => 'Teatro per bambini']);
        $category->refresh();

        $this->get('/locali/'.$vecchioLocale)->assertRedirect('/locali/'.$venue->slug);
        $this->get('/eventi/tag/'.$vecchioTag)->assertRedirect('/eventi/tag/'.$tag->slug);
        $this->get('/eventi/categoria/'.$vecchiaCategoria)->assertRedirect('/eventi/categoria/'.$category->slug);
    });

    /*
     * La rinomina di una città sposta **tutte** le pagine in un colpo solo: il
     * suo slug è il prefisso di ogni rotta del sito pubblico. Una riga per
     * indirizzo sarebbe un elenco che nasce incompleto.
     */
    it('con il jolly copre un ramo intero quando cambia la citta', function (): void {
        $city = testCity();
        $vecchia = $city->slug;

        $city->update(['name' => 'Padova e provincia']);
        $city->refresh();

        expect($city->slug)->not->toBe($vecchia);

        $this->get('/'.$vecchia.'/eventi/weekend')
            ->assertRedirect('/'.$city->slug.'/eventi/weekend');
    });
});

describe('le due trappole di ogni tabella di reindirizzamenti', function (): void {
    /*
     * A diventa B, poi B diventa C: la riga A→B punterebbe a un indirizzo che
     * non esiste più, e chi arriva da A farebbe un salto per finire su un 404.
     */
    it('appiattisce la catena in un salto solo', function (): void {
        $city = testCity();
        $category = testCategory();

        $event = Event::factory()->published()->create([
            'city_id' => $city->id,
            'category_id' => $category->id,
            'title' => 'Primo nome',
        ]);

        $primo = $event->slug;

        $event->update(['title' => 'Secondo nome', 'slug' => 'secondo-nome']);
        $event->refresh();
        $secondo = $event->slug;

        $event->update(['title' => 'Terzo nome', 'slug' => 'terzo-nome']);
        $event->refresh();

        $this->get('/eventi/'.$primo)->assertRedirect('/eventi/'.$event->slug);
        $this->get('/eventi/'.$secondo)->assertRedirect('/eventi/'.$event->slug);

        expect(Redirect::query()->where('from_path', '/eventi/'.$primo)->value('to_path'))
            ->toBe('/eventi/'.$event->slug);
    });

    /*
     * A diventa B e poi qualcuno ci ripensa: senza guardia restano due righe
     * che si rimandano a vicenda, e il browser si ferma su «troppi
     * reindirizzamenti» — cioè l'indirizzo che si voleva salvare diventa
     * irraggiungibile proprio per averlo salvato.
     */
    it('non lascia due righe che si rimandano a vicenda', function (): void {
        $city = testCity();
        $category = testCategory();

        $event = Event::factory()->published()->create([
            'city_id' => $city->id,
            'category_id' => $category->id,
            'title' => 'Nome di partenza',
        ]);

        $primo = $event->slug;

        $event->update(['title' => 'Nome nuovo', 'slug' => 'nome-nuovo']);
        $event->refresh();
        $secondo = $event->slug;

        $event->update(['slug' => $primo]);
        $event->refresh();

        expect($event->slug)->toBe($primo);

        /* Il vecchio indirizzo è tornato a essere quello buono: nessuna riga
           deve mandarlo altrove. */
        expect(Redirect::query()->where('from_path', '/eventi/'.$primo)->exists())->toBeFalse();

        $this->get('/eventi/'.$primo)->assertOk();
        $this->get('/eventi/'.$secondo)->assertRedirect('/eventi/'.$primo);
    });
});

describe('quello che non deve diventare un reindirizzamento', function (): void {
    it('lascia il 404 dov era quando non c e una riga', function (): void {
        testCity();

        $this->get('/eventi/non-e-mai-esistito')->assertNotFound();
    });

    /*
     * Un invio finito in 404 non deve diventare un 301: il corpo verrebbe
     * buttato via e il modulo sarebbe perso senza che nessuno lo sappia. La
     * prima riga di questo test serve anche a dire che l'indirizzo un
     * reindirizzamento ce l'ha davvero — senza, la seconda passerebbe da sola.
     */
    it('non trasforma un invio in un reindirizzamento', function (): void {
        $city = testCity();

        app(RegistroRedirect::class)->registra(
            (int) $city->getKey(),
            '/sezione-sparita/modulo',
            '/pagine/chi-siamo',
        );

        $this->get('/sezione-sparita/modulo')->assertRedirect('/pagine/chi-siamo');
        $this->post('/sezione-sparita/modulo')->assertNotFound();
    });

    it('lascia l API in JSON', function (): void {
        testCity();

        app(RegistroRedirect::class)->registra(null, '/api/v1/eventi/vecchio', '/eventi/nuovo');

        $risposta = $this->getJson('/api/v1/eventi/vecchio');

        $risposta->assertNotFound();
        expect($risposta->headers->get('Content-Type'))->toContain('json');
    });
});

describe('contabilita', function (): void {
    /*
     * Senza questi due numeri l'elenco diventa un deposito che nessuno osa
     * potare: è la differenza fra sapere che un vecchio indirizzo è ancora il
     * più visitato del sito e sospettarlo.
     */
    it('conta i passaggi e non finge che la riga sia stata modificata', function (): void {
        $city = testCity();

        app(RegistroRedirect::class)->registra((int) $city->getKey(), '/eventi/vecchio', '/eventi/nuovo');

        $riga = Redirect::query()->firstOrFail();
        $modificataIl = $riga->updated_at;

        $this->get('/eventi/vecchio');
        $this->get('/eventi/vecchio');

        $riga->refresh();

        expect($riga->hits)->toBe(2)
            ->and($riga->last_hit_at)->not->toBeNull()
            ->and($riga->updated_at?->toDateTimeString())->toBe($modificataIl?->toDateTimeString());
    });
});

describe('i reindirizzamenti non entrano dove non devono', function (): void {
    it('non finiscono nella mappa del sito', function (): void {
        $city = testCity();
        $category = testCategory();

        $event = Event::factory()->published()->create([
            'city_id' => $city->id,
            'category_id' => $category->id,
            'title' => 'Evento con un nome sbagliato',
        ]);

        EventOccurrence::factory()->create(['event_id' => $event->id]);

        $vecchio = $event->slug;
        $event->update(['title' => 'Evento con il nome giusto', 'slug' => 'evento-con-il-nome-giusto']);
        $event->refresh();

        $this->get('/sitemap.xml')->assertOk();

        $sezioni = $this->get('/sitemap-eventi-1.xml');
        $sezioni->assertOk()
            ->assertDontSee('/eventi/'.$vecchio)
            ->assertSee('/eventi/'.$event->slug, escape: false);
    });

    /*
     * `CachePage::isStorable()` accetta solo un 200 in `text/html`. Vale la
     * pena verificarlo invece di ricordarselo: una copia salvata di un 301
     * sopravvivrebbe alla correzione della riga che l'ha prodotto.
     */
    it('un 301 non finisce nella full-page cache', function (): void {
        config()->set('page_cache.enabled', true);

        $city = testCity();
        $category = testCategory();

        $event = Event::factory()->published()->create([
            'city_id' => $city->id,
            'category_id' => $category->id,
            'title' => 'Serata da rinominare',
        ]);

        $vecchio = $event->slug;
        $event->update(['title' => 'Serata rinominata', 'slug' => 'serata-rinominata']);
        $event->refresh();

        $this->get('/eventi/'.$vecchio)->assertRedirect('/eventi/'.$event->slug);

        $seconda = $this->get('/eventi/'.$vecchio);
        $seconda->assertRedirect('/eventi/'.$event->slug)
            ->assertHeaderMissing('X-Page-Cache');
    });
});
