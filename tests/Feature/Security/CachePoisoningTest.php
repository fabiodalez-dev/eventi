<?php

declare(strict_types=1);

use App\Http\Middleware\CachePage;
use App\Http\Requests\Web\EventFilterRequest;
use App\Http\Requests\Web\VenueFilterRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * La chiave della full-page cache deve distinguere tutto ciò che cambia la
 * pagina, e **niente** che non la cambi.
 *
 * Sbagliare per difetto è avvelenamento: `/eventi?budget=0` e `/eventi`
 * diventano la stessa voce, quindi il primo anonimo che passa con quel
 * parametro decide cosa vedono tutti per un minuto. Sbagliare per eccesso è
 * riempimento del disco: ogni valore distinto è un file, e questo sito gira su
 * uno spazio da 10 GB già esaurito una volta.
 */
beforeEach(function (): void {
    config()->set('page_cache.enabled', true);
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-12 18:00');
    occurrenceAtLocal($this->city, testCategory(), '2026-09-12 21:30');
    $this->middleware = app(CachePage::class);
});

/**
 * Il test che impedisce al buco di riaprirsi.
 *
 * Il difetto non era una riga sbagliata, era **due elenchi che divergono**:
 * i filtri stanno in `EventFilterRequest` e `VenueFilterRequest`, la chiave in
 * `CachePage::QUERY_ALLOWED`, e chi aggiunge un filtro non ha alcun motivo di
 * ricordarsi del secondo. È già successo quattro volte — `budget`,
 * `discovery`, `days`, `membership` — e l'ultima di quelle era di ieri.
 *
 * Da qui in avanti, aggiungere un filtro senza decidere cosa farne nella
 * chiave fa fallire questa riga.
 */
it('non lascia divergere i filtri dichiarati dalla chiave della cache', function (): void {
    $filtri = array_merge(
        array_keys((new EventFilterRequest)->rules()),
        array_keys((new VenueFilterRequest)->rules()),
    );

    /* I due che restano fuori di proposito, e che rendono la pagina NON
       conservabile invece di entrare in chiave: la ricerca libera perché ogni
       ricerca è diversa, il budget perché è un intero su diecimila valori. */
    $fuoriDiProposito = ['q', 'budget'];

    $mancanti = array_values(array_diff(
        array_unique($filtri),
        CachePage::QUERY_ALLOWED,
        $fuoriDiProposito,
    ));

    expect($mancanti)->toBe([], 'Filtri che cambiano i risultati ma non la chiave della cache: '.implode(', ', $mancanti));
});

it('distingue le pagine sui filtri che prima si confondevano', function (): void {
    $nuda = $this->middleware->key(Request::create('/eventi'));

    foreach (['discovery=1', 'membership=required', 'days=30'] as $filtro) {
        expect($this->middleware->key(Request::create('/eventi?'.$filtro)))
            ->not->toBe($nuda, "Il filtro «{$filtro}» non entra nella chiave");
    }
});

it('non serve dalla cache una pagina con il budget, che resta personale', function (): void {
    $this->get('/eventi?budget=0')->assertOk()->assertHeaderMissing('X-Page-Cache');
    $this->get('/eventi?budget=0')->assertOk()->assertHeaderMissing('X-Page-Cache');
});

it('rifiuta di conservare pagine con valori fuori misura', function (): void {
    /* Un file per ogni intero, e gli interi non finiscono. */
    $this->get('/eventi?page=999999')->assertOk()->assertHeaderMissing('X-Page-Cache');

    /* Un valore lunghissimo: la pagina si disegna, la copia non si scrive. */
    $this->get('/eventi?category='.str_repeat('a', 200))->assertOk()->assertHeaderMissing('X-Page-Cache');

    /* Una data che non è una data. */
    $this->get('/eventi?from=qualunque-cosa')->assertOk()->assertHeaderMissing('X-Page-Cache');
});

it('tratta come una sola pagina lo stesso raggio scritto in modi diversi', function (): void {
    /* `5`, `5.0` e `5.0000001` sono la stessa richiesta: senza
       l'arrotondamento bastavano le cifre decimali per moltiplicare i file. */
    $base = $this->middleware->key(Request::create('/eventi?radius=5'));

    expect($this->middleware->key(Request::create('/eventi?radius=5.0')))->toBe($base)
        ->and($this->middleware->key(Request::create('/eventi?radius=5.0000001')))->toBe($base)
        ->and($this->middleware->key(Request::create('/eventi?radius=10')))->not->toBe($base);
});

/*
 * Il contrappeso: la cache deve continuare a funzionare per le pagine vere,
 * altrimenti si è chiuso un buco spegnendo la funzione.
 */
it('conserva e riserve le pagine normali', function (): void {
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'miss');
    $this->get('/eventi')->assertHeader('X-Page-Cache', 'hit');

    $this->get('/eventi?page=2')->assertHeader('X-Page-Cache', 'miss');
    $this->get('/eventi?page=2')->assertHeader('X-Page-Cache', 'hit');
});

it('does not cache decimal page numbers and bounds stored category combinations', function (): void {
    $this->get('/eventi?page=1.00001')->assertOk()->assertHeaderMissing('X-Page-Cache');
    config()->set('page_cache.max_entries', 3);
    foreach (range(1, 8) as $number) {
        $this->get('/eventi?category=arbitrary-'.$number)->assertOk();
    }
    $store = Cache::store(config('page_cache.store') ?: null);
    expect($store->get('page-cache-index'))->toHaveCount(3);
    expect($store->has($this->middleware->key(Request::create('/eventi?category=arbitrary-1'))))->toBeFalse();
});
