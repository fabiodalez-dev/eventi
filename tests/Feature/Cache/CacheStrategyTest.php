<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\City;
use App\Models\Venue;
use App\Services\Cache\LiveWindows;
use App\Services\Calendar\MonthCalendar;
use App\Services\Search\FilterFacets;
use App\Support\ContentVersion;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * La tabella di §12.3, riga per riga.
 */
describe('chiave arrotondata al quarto d\'ora', function (): void {
    /*
     * È il punto su cui §12.3 insiste: «l'arrotondamento al quarto d'ora è ciò
     * che rende la cache utile: senza, ogni secondo genera una chiave diversa e
     * la cache non serve a niente».
     */
    it('da la stessa chiave a tre secondi di distanza', function (): void {
        $city = testCity();

        freezeLocal($city, '2026-09-12 21:03:07');
        $prima = LiveWindows::key($city, LiveWindows::ONGOING);

        freezeLocal($city, '2026-09-12 21:03:10');
        $dopo = LiveWindows::key($city, LiveWindows::ONGOING);

        expect($dopo)->toBe($prima);
    });

    it('da una chiave diversa a venti minuti di distanza', function (): void {
        $city = testCity();

        freezeLocal($city, '2026-09-12 21:03:07');
        $prima = LiveWindows::key($city, LiveWindows::ONGOING);

        freezeLocal($city, '2026-09-12 21:23:07');
        $dopo = LiveWindows::key($city, LiveWindows::ONGOING);

        expect($dopo)->not->toBe($prima);
    });

    it('cambia esattamente allo scoccare del quarto d\'ora', function (): void {
        $city = testCity();

        freezeLocal($city, '2026-09-12 21:14:59');
        $prima = LiveWindows::key($city, LiveWindows::ONGOING);

        freezeLocal($city, '2026-09-12 21:15:00');
        $dopo = LiveWindows::key($city, LiveWindows::ONGOING);

        expect($prima)->toContain('21:00')
            ->and($dopo)->toContain('21:15');
    });

    it('tiene separate le due finestre e le due citta', function (): void {
        $padova = testCity();
        $verona = City::factory()->padova()->create(['name' => 'Verona', 'slug' => 'verona']);

        freezeLocal($padova, '2026-09-12 21:03:00');

        expect(LiveWindows::key($padova, LiveWindows::ONGOING))
            ->not->toBe(LiveWindows::key($padova, LiveWindows::STARTING_SOON))
            ->not->toBe(LiveWindows::key($verona, LiveWindows::ONGOING));
    });

    it('non ricalcola la finestra dentro lo stesso quarto d\'ora', function (): void {
        $city = testCity();
        $category = testCategory();

        occurrenceAtLocal($city, $category, '2026-09-12 20:00', '2026-09-12 23:00');

        $windows = app(LiveWindows::class);
        $conteggio = 0;
        DB::listen(function () use (&$conteggio): void {
            $conteggio++;
        });

        freezeLocal($city, '2026-09-12 21:03:07');
        $windows->ongoing($city, 6);
        $freddo = $conteggio;

        /*
         * A tre secondi di distanza la chiave è la stessa: l'interrogazione del
         * motore — quella con le giunzioni e le finestre — non parte più, e
         * resta solo la rilettura per chiave primaria con le sue relazioni.
         */
        $conteggio = 0;
        freezeLocal($city, '2026-09-12 21:03:10');

        expect($windows->ongoing($city, 6))->toHaveCount(1)
            ->and($conteggio)->toBeLessThan($freddo);
    });
});

describe('full-page cache dello scheletro', function (): void {
    beforeEach(function (): void {
        config()->set('page_cache.enabled', true);
    });

    it('serve la seconda richiesta dalla copia salvata', function (): void {
        $city = testCity();
        $category = testCategory();
        occurrenceAtLocal($city, $category, '2026-09-12 21:30');

        freezeLocal($city, '2026-09-12 18:00');

        $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'miss');
        $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'hit');
    });

    it('smette di servire la copia quando un evento viene pubblicato', function (): void {
        $city = testCity();
        $category = testCategory();
        occurrenceAtLocal($city, $category, '2026-09-12 21:30');

        freezeLocal($city, '2026-09-12 18:00');

        $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');
        $this->get('/eventi')->assertHeader('X-Page-Cache', 'hit');

        occurrenceAtLocal($city, $category, '2026-09-13 21:30', event: ['title' => 'Serata nuova']);

        $this->get('/eventi')
            ->assertHeader('X-Page-Cache', 'miss')
            ->assertSee('Serata nuova');
    });

    it('rimette il token della sessione che sta guardando', function (): void {
        $city = testCity();
        $category = testCategory();
        occurrenceAtLocal($city, $category, '2026-09-12 21:30');

        freezeLocal($city, '2026-09-12 18:00');

        $this->get('/eventi');

        $risposta = $this->get('/eventi');

        $risposta->assertHeader('X-Page-Cache', 'hit')
            ->assertSee(csrf_token(), escape: false)
            ->assertDontSee('@@csrf-token@@');
    });

    it('non mette in cache la ricerca ne una posizione', function (): void {
        $city = testCity();
        $category = testCategory();
        occurrenceAtLocal($city, $category, '2026-09-12 21:30');

        freezeLocal($city, '2026-09-12 18:00');

        $this->get('/cerca?q=concerto')->assertOk()->assertHeaderMissing('X-Page-Cache');

        $this->get('/eventi?near='.$city->center_lat.','.$city->center_lng.'&radius_km=5')
            ->assertOk()
            ->assertHeaderMissing('X-Page-Cache');
    });
});

describe('conteggi del calendario', function (): void {
    it('sta in cache per mezz\'ora e cade alla pubblicazione', function (): void {
        $city = testCity();
        $category = testCategory();
        occurrenceAtLocal($city, $category, '2026-09-12 21:30');

        freezeLocal($city, '2026-09-12 18:00');

        $calendario = app(MonthCalendar::class);
        $mese = CarbonImmutable::parse('2026-09-01', $city->timezone);

        expect($calendario->digest($city, $mese)['2026-09-12']['count'])->toBe(1);

        occurrenceAtLocal($city, $category, '2026-09-12 22:30');

        expect($calendario->digest($city, $mese)['2026-09-12']['count'])->toBe(2);
    });
});

describe('tassonomie', function (): void {
    it('stanno in cache per ventiquattro ore', function (): void {
        Cache::flush();

        testCity();
        Category::factory()->create(['name' => 'Teatro', 'slug' => 'teatro']);

        $facets = app(FilterFacets::class);

        expect($facets->categories())->toHaveCount(1);

        Category::factory()->create(['name' => 'Cinema', 'slug' => 'cinema']);

        // La seconda categoria non compare: l'elenco arriva dalla copia salvata.
        expect($facets->categories())->toHaveCount(1);

        Carbon::setTestNow(CarbonImmutable::now()->addHours(25));

        expect($facets->categories())->toHaveCount(2);
    });

    it('rivede i locali di una citta appena qualcosa viene pubblicato', function (): void {
        $city = testCity();

        $facets = app(FilterFacets::class);
        expect($facets->venues($city))->toHaveCount(0);

        Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

        // Senza il cambio di versione l'elenco resterebbe quello di prima.
        expect($facets->venues($city))->toHaveCount(0);

        ContentVersion::bump($city);

        expect($facets->venues($city))->toHaveCount(1);
    });
});

/*
 * La cache di produzione è `file` (D5), e `cache.serializable_classes` è
 * `false`: nessuna classe PHP viene ricostruita da ciò che sta in cache. Il
 * driver `array` dei test non serializza nulla, quindi un oggetto messo in
 * cache **passa** nei test e **rompe** in produzione con un
 * `__PHP_Incomplete_Class`. Questi due test girano sul driver vero.
 */
describe('cio che finisce in cache regge il driver file', function (): void {
    beforeEach(function (): void {
        config()->set('cache.default', 'file');
        Cache::store('file')->flush();
    });

    afterEach(function (): void {
        Cache::store('file')->flush();
        config()->set('cache.default', 'array');
    });

    it('rilegge la mappa del sito dalla copia su disco', function (): void {
        $city = testCity();
        $category = testCategory();
        occurrenceAtLocal($city, $category, '2026-09-12 21:30');

        freezeLocal($city, '2026-09-01 12:00');

        $prima = $this->get('/sitemap-eventi-1.xml')->assertOk()->getContent();
        $seconda = $this->get('/sitemap-eventi-1.xml')->assertOk()->getContent();

        expect($seconda)->toBe($prima)
            ->and($seconda)->toContain('<loc>');
    });

    it('rilegge le tassonomie dalla copia su disco', function (): void {
        $city = testCity();
        Category::factory()->create(['name' => 'Teatro', 'slug' => 'teatro']);

        $facets = app(FilterFacets::class);

        expect($facets->categories())->toHaveCount(1);

        // La seconda lettura passa dal disco: se in cache fossero finiti dei
        // modelli, qui uscirebbe un __PHP_Incomplete_Class.
        expect($facets->categories()->first()->name)->toBe('Teatro')
            ->and($facets->venues($city))->toHaveCount(0);
    });
});
