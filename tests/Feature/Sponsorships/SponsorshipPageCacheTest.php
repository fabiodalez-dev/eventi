<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Sponsorship\Delivery\Draw;
use App\Services\Sponsorship\Delivery\HashDraw;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use App\Services\Sponsorship\Delivery\SeededDraw;
use App\Support\ContentVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * Le sponsorizzazioni con la cache di pagina accesa.
 *
 * Nei test la cache di pagina è spenta (`PAGE_CACHE_ENABLED=false`), quindi
 * nessuno aveva mai guardato una campagna dentro una pagina servita dalla
 * copia salvata: per un anonimo la scelta restava ferma fino a trenta minuti,
 * e una campagna in pausa o arrivata al tetto restava a schermo. Qui la cache
 * è accesa, e si guarda la pagina come la vede chi non è collegato.
 */
beforeEach(function (): void {
    config()->set('page_cache.enabled', true);
    Cache::flush();

    $this->city = testCity();
    freezeLocal($this->city, '2026-11-20 10:00');
    $this->category = testCategory();
    app()->instance(Draw::class, new SeededDraw(20261119));
});

/** Una campagna su una serata di stasera o dei prossimi giorni. */
function campagnaInCache(SponsorshipPlacement $placement, string $nome, string $quando = '2026-11-21 21:00', array $attributi = []): Sponsorship
{
    $date = occurrenceAtLocal(test()->city, test()->category, $quando);

    return Sponsorship::factory()->placement($placement)->create([
        'city_id' => test()->city->getKey(),
        'event_id' => $date->event_id,
        'advertiser_name' => $nome,
        ...$attributi,
    ]);
}

/** Gli id delle campagne sponsorizzate nella pagina, nell'ordine. @return list<int> */
function campagneInPagina(TestResponse $risposta): array
{
    preg_match_all('/data-sponsorship="(\d+)"/', (string) $risposta->getContent(), $trovate);

    return array_map('intval', $trovate[1]);
}

/** I gettoni delle visualizzazioni nella pagina. @return list<string> */
function gettoniInPagina(TestResponse $risposta): array
{
    preg_match_all('/data-sponsorship-token="([^"]+)"/', (string) $risposta->getContent(), $trovati);

    return $trovati[1];
}

/**
 * La pagina senza ciò che cambia a ogni risposta per costruzione: le regioni
 * delle sponsorizzazioni, i gettoni, il token CSRF, il nonce della CSP e,
 * nella griglia, le date delle campagne (la card scelta toglie la propria).
 *
 * @param  list<int>  $promosse
 */
function scheletro(TestResponse $risposta, array $promosse = []): string
{
    $html = (string) $risposta->getContent();

    foreach ($promosse as $id) {
        $html = preg_replace('/<article data-flip-id="occurrence-'.$id.'"[^>]*>.*?<\/article>/s', '', $html) ?? $html;
    }

    $html = preg_replace('/<!-- page-cache-sponsor:start [A-Za-z0-9_-]+ -->.*?<!-- page-cache-sponsor:end -->/s', '[sponsor]', $html) ?? $html;
    $html = preg_replace('/<!-- page-cache-live-now:start -->.*?<!-- page-cache-live-now:end -->/s', '[live]', $html) ?? $html;
    $html = preg_replace('/v1\.\d+\.[a-z_]+\.\d+\.[0-9a-f]{16}\.[A-Za-z0-9_-]{43}/', '[token]', $html) ?? $html;
    $html = preg_replace('/nonce="[^"]*"/', 'nonce=""', $html) ?? $html;

    return str_replace(csrf_token(), '[csrf]', $html);
}

/** La copia salvata, così com'è sul disco. */
function copiaSalvata(): string
{
    $keys = Cache::get('page-cache-index', []);
    $raw = (string) Cache::get((string) end($keys));

    return str_starts_with($raw, "\x1f\x8b") ? (string) gzdecode($raw) : $raw;
}

it('serve la seconda richiesta dalla copia, con lo stesso scheletro e frammento e gettone nuovi', function (): void {
    $alfa = campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta Alfa');
    $beta = campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta Beta', '2026-11-22 21:00');

    $prima = $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'miss');
    $risposte = collect(range(1, 8))->map(fn (): TestResponse => $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'hit'));

    $scelte = $risposte->map(fn (TestResponse $r): array => campagneInPagina($r));
    $gettoni = $risposte->flatMap(fn (TestResponse $r): array => gettoniInPagina($r));

    $date = [$alfa->event->occurrences()->value('id'), $beta->event->occurrences()->value('id')];

    expect($risposte->map(fn (TestResponse $r): string => scheletro($r, $date))->unique())->toHaveCount(1)
        ->and(scheletro($risposte->first(), $date))->toBe(scheletro($prima, $date))
        /* Una card per pagina, e su otto visite tutte e due le campagne. */
        ->and($scelte->every(fn (array $ids): bool => count($ids) === 1))->toBeTrue()
        ->and($scelte->flatten()->unique()->sort()->values()->all())->toBe([$alfa->getKey(), $beta->getKey()])
        /* Un gettone nuovo a ogni visita, e valido. */
        ->and($gettoni)->toHaveCount(8)
        ->and($gettoni->unique())->toHaveCount(8)
        ->and($gettoni->every(fn (string $gettone): bool => app(ImpressionToken::class)->verify($gettone, CarbonImmutable::now())?->placement === 'list_top'))->toBeTrue();
});

it('non salva nella copia né la card né un gettone', function (): void {
    campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta Alfa');
    campagnaInCache(SponsorshipPlacement::HomeHero, 'Apertura Gamma');

    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'miss');
    $lista = copiaSalvata();
    $this->get('/')->assertOk()->assertHeader('X-Page-Cache', 'miss');
    $home = copiaSalvata();

    expect($lista)->toContain('@@sponsor-slot:')
        ->and($lista)->not->toContain('Etichetta Alfa')
        ->and($lista)->not->toMatch('/v1\.\d+\.[a-z_]+\.\d+\.[0-9a-f]{16}\./')
        /* L'apertura resta nello scheletro, ma con il segnaposto del gettone. */
        ->and($home)->toContain('Apertura Gamma')
        ->and($home)->toMatch('/data-sponsorship-token="@@sponsor-token:\d+:home_hero@@"/')
        ->and($home)->not->toMatch('/v1\.\d+\.[a-z_]+\.\d+\.[0-9a-f]{16}\./');
});

it('fa sparire una campagna in pausa entro un minuto, senza svuotare la cache', function (): void {
    $alfa = campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta Alfa');

    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'miss')->assertSee('Etichetta Alfa');
    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'hit')->assertSee('Etichetta Alfa');

    /* Una pausa che non passa dal modello: non fa invecchiare la pagina, come
       una fine campagna o un obiettivo raggiunto. */
    Sponsorship::query()->whereKey($alfa->getKey())->update(['status' => 'paused']);

    $this->travel(1)->minutes();
    Artisan::call('sponsorships:plan');

    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'hit')
        ->assertDontSee('Etichetta Alfa')
        ->assertDontSee('data-sponsorship=', false);
});

it('fa sparire una campagna finita anche se il cron è fermo', function (): void {
    campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta Alfa', attributi: ['ends_at' => CarbonImmutable::now('UTC')->addMinutes(2)]);

    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'miss')->assertSee('Etichetta Alfa');

    /* Tre minuti dopo: la campagna è finita e il piano ha più di due minuti,
       quindi chi serve la pagina lo ricostruisce da sé. */
    $this->travel(3)->minutes();

    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'hit')->assertDontSee('Etichetta Alfa');
});

it('fa sparire una campagna arrivata all obiettivo', function (): void {
    /* A visualizzazioni e «subito»: quota 0,8, il resto al contenuto della
       redazione. Su dieci visite compare. */
    $alfa = campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta Alfa', attributi: ['impressions_goal' => 10, 'pacing' => 'asap']);

    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'miss');
    $prima = collect(range(1, 10))->filter(fn (): bool => str_contains((string) $this->get('/eventi')->getContent(), 'Etichetta Alfa'))->count();

    /* Le misure incrementano il contatore senza salvare il modello. */
    Sponsorship::query()->whereKey($alfa->getKey())->increment('impressions', 10);
    $this->travel(1)->minutes();
    Artisan::call('sponsorships:plan');

    $dopo = collect(range(1, 10))->filter(fn (): bool => str_contains((string) $this->get('/eventi')->assertHeader('X-Page-Cache', 'hit')->getContent(), 'Etichetta Alfa'))->count();

    expect($prima)->toBeGreaterThan(4)
        ->and($dopo)->toBe(0);
});

it('lascia la stessa apertura dentro la casella anche dopo un cambio dei contenuti', function (): void {
    $gamma = campagnaInCache(SponsorshipPlacement::HomeHero, 'Apertura Gamma');
    $delta = campagnaInCache(SponsorshipPlacement::HomeHero, 'Apertura Delta', '2026-11-22 21:00');

    $minuti = HashDraw::slotMinutes();
    $casella = HashDraw::slotIndex(CarbonImmutable::now(), $minuti);
    $this->travelTo(CarbonImmutable::createFromTimestamp($casella * $minuti * 60 + 60, 'UTC'));

    $apertura = fn (TestResponse $r): ?int => preg_match('/data-home-hero[^>]*data-sponsorship="(\d+)"/s', (string) $r->getContent(), $m) === 1 ? (int) $m[1] : null;

    $prima = $this->get('/')->assertOk()->assertHeader('X-Page-Cache', 'miss');
    ContentVersion::bump($this->city);
    $dopo = $this->get('/')->assertOk()->assertHeader('X-Page-Cache', 'miss');
    $this->travel($minuti - 3)->minutes();
    $fine = $this->get('/')->assertOk()->assertHeader('X-Page-Cache', 'hit');

    expect($apertura($prima))->not->toBeNull()
        ->and($apertura($dopo))->toBe($apertura($prima))
        ->and($apertura($fine))->toBe($apertura($prima));

    /* Casella per casella le due campagne si alternano. */
    $viste = [];

    foreach (range(1, 20) as $avanti) {
        $this->travel($minuti)->minutes();
        $viste[] = $apertura($this->get('/')->assertOk());
    }

    expect(array_values(array_unique($viste)))->toEqualCanonicalizing([$gamma->getKey(), $delta->getKey()]);
});

it('toglie dalle sezioni la data che mostra in apertura', function (): void {
    $gamma = campagnaInCache(SponsorshipPlacement::HomeHero, 'Apertura Gamma', '2026-11-20 21:00');

    $this->get('/')->assertOk()
        ->assertSee('Apertura Gamma')
        ->assertViewHas('sections', fn (array $sezioni): bool => collect($sezioni)->flatten()
            ->every(fn ($occorrenza): bool => (int) $occorrenza->event_id !== (int) $gamma->event_id));
});

it('serve la pagina dalla cache senza nessuna query', function (): void {
    campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta Alfa');
    campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta Beta', '2026-11-22 21:00');

    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'miss');

    $query = [];
    DB::listen(function ($evento) use (&$query): void {
        $query[] = $evento->sql;
    });

    foreach (range(1, 5) as $volta) {
        $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'hit')->assertSee('data-sponsorship-token="v1.', false);
    }

    expect($query)->toBe([]);
});

it('non ripete fra i risultati la data promossa in cima', function (): void {
    $alfa = campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta Alfa', '2026-11-20 21:00');
    occurrenceAtLocal($this->city, $this->category, '2026-11-21 20:00');
    $data = $alfa->event->occurrences()->firstOrFail();
    $carta = 'data-flip-id="occurrence-'.$data->getKey().'"';

    $mancata = $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'miss');
    $presa = $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'hit');

    /* Una volta sola: nella card in cima, non anche nella griglia. La copia
       salvata invece tiene la griglia intera. */
    expect(substr_count((string) $mancata->getContent(), $carta))->toBe(1)
        ->and(substr_count((string) $presa->getContent(), $carta))->toBe(1)
        ->and(substr_count(copiaSalvata(), $carta))->toBe(1)
        ->and((string) $presa->getContent())->toContain('Etichetta Alfa');
});

it('preferisce nell elenco filtrato la campagna che rispetta i filtri', function (): void {
    $cinema = testCategory(['name' => 'Cinema', 'slug' => 'cinema-prova']);
    $pertinente = occurrenceAtLocal($this->city, $cinema, '2026-11-21 21:30');
    campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta Generica', attributi: ['priority' => 100]);
    Sponsorship::factory()->placement(SponsorshipPlacement::ListTop)->create([
        'city_id' => $this->city->getKey(), 'event_id' => $pertinente->event_id, 'advertiser_name' => 'Etichetta Cinema', 'priority' => 1,
    ]);

    $this->get('/eventi?category=cinema-prova')->assertOk()->assertHeader('X-Page-Cache', 'miss')->assertSee('Etichetta Cinema')->assertDontSee('Etichetta Generica');

    foreach (range(1, 4) as $volta) {
        $this->get('/eventi?category=cinema-prova')->assertOk()->assertHeader('X-Page-Cache', 'hit')
            ->assertSee('Etichetta Cinema')->assertDontSee('Etichetta Generica');
    }
});

it('riempie anche la card della home a ogni visita, con l etichetta di D53', function (): void {
    $uno = campagnaInCache(SponsorshipPlacement::HomeCard, 'Consiglio Uno');
    $due = campagnaInCache(SponsorshipPlacement::HomeCard, 'Consiglio Due', '2026-11-23 21:00');

    $this->get('/')->assertOk()->assertHeader('X-Page-Cache', 'miss');
    $viste = collect(range(1, 8))->map(fn (): TestResponse => $this->get('/')->assertOk()->assertHeader('X-Page-Cache', 'hit'));

    expect(copiaSalvata())->not->toContain('Consiglio Uno')->not->toContain('Consiglio Due')
        ->and($viste->flatMap(fn (TestResponse $r): array => campagneInPagina($r))->unique()->sort()->values()->all())
        ->toBe([$uno->getKey(), $due->getKey()]);

    foreach ($viste as $vista) {
        $html = (string) $vista->getContent();

        expect($html)->toContain(e(__('sponsorships.label')))
            ->and($html)->toContain('rel="sponsored"')
            ->and(str_contains($html, e(__('sponsorships.by', ['advertiser' => 'Consiglio Uno'])))
                || str_contains($html, e(__('sponsorships.by', ['advertiser' => 'Consiglio Due']))))->toBeTrue();
    }
});

it('non mette niente quando non c è nessuna campagna, e la card compare appena ce n è una', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-11-21 21:00');
    $vuota = $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'miss')->assertDontSee('data-sponsorship=', false)
        ->assertSee('<div aria-hidden="true" data-sponsorship-vacant="list_top" data-sponsorship-opportunity="list_top" data-sponsorship-city="'.$this->city->getKey().'" data-sponsorship-opportunity-token="o1.', false);
    $daCache = $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'hit');

    /* Il gettone dell'occasione è nuovo a ogni visita, come quelli delle card. */
    preg_match('/data-sponsorship-opportunity-token="([^"]+)"/', (string) $vuota->getContent(), $primo);
    preg_match('/data-sponsorship-opportunity-token="([^"]+)"/', (string) $daCache->getContent(), $secondo);
    expect($secondo[1])->not->toBe($primo[1])
        ->and(app(ImpressionToken::class)->verifyOpportunity($secondo[1], CarbonImmutable::now()))->toMatchArray(['placement' => 'list_top'])
        ->and(copiaSalvata())->not->toContain('o1.');

    /* Una campagna che parte da sola, senza nessun salvataggio che faccia
       invecchiare la pagina. */
    Sponsorship::withoutEvents(fn () => Sponsorship::factory()->placement(SponsorshipPlacement::ListTop)->create([
        'city_id' => $this->city->getKey(), 'event_id' => $date->event_id, 'advertiser_name' => 'Etichetta Nuova',
    ]));
    $this->travel(3)->minutes();

    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'hit')->assertSee('Etichetta Nuova')
        ->assertDontSee('data-sponsorship-vacant', false);
});

it('segna lo spazio vuoto anche per chi è collegato', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-11-21 21:00');

    $this->actingAs(User::factory()->create())
        ->get('/eventi')->assertOk()->assertHeaderMissing('X-Page-Cache')
        ->assertSee('<div aria-hidden="true" data-sponsorship-vacant="list_top" data-sponsorship-opportunity="list_top" data-sponsorship-city="'.$this->city->getKey().'" data-sponsorship-opportunity-token="o1.', false);
});

/** Tutti i gettoni validi nella pagina, ovunque stiano. @return list<\App\Services\Sponsorship\Delivery\ImpressionTokenClaims> */
function gettoniValidiOvunque(TestResponse $risposta): array
{
    preg_match_all('/v1\.\d+\.[a-z_]+\.\d+\.[0-9a-f]{16}\.[A-Za-z0-9_-]{43}/', (string) $risposta->getContent(), $trovati);

    return array_values(array_filter(array_map(
        fn (string $gettone) => app(ImpressionToken::class)->verify($gettone, CarbonImmutable::now()),
        $trovati[0],
    )));
}

it('non firma i gettoni né riempie i buchi scritti nel titolo di un evento', function (): void {
    $alfa = campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta Alfa');
    $concorrente = campagnaInCache(SponsorshipPlacement::HomeCard, 'Concorrente', '2026-11-23 21:00');

    /* Un gettone vero copiato da un'altra pagina (firmato altrove, quindi non
       emesso da questo processo), uno con la sola forma, i segnaposto dei
       gettoni, dei buchi e del riempimento, e quelli della cache di pagina. */
    $copiato = app(ImpressionToken::class)->sign((int) $concorrente->getKey(), 'home_card', CarbonImmutable::now(), 'abcdefabcdefabcd');
    $finto = 'v1.'.$concorrente->getKey().'.home_card.1.0000000000000000.'.str_repeat('A', 43);
    $buco = rtrim(strtr(base64_encode((string) json_encode(['c' => (int) $this->city->getKey(), 'p' => 'home_card', 'q' => null, 'e' => null])), '+/', '-_'), '=');
    occurrenceAtLocal($this->city, $this->category, '2026-11-21 20:00', event: ['title' => 'Trappola '.$copiato.' '.$finto]);
    occurrenceAtLocal($this->city, $this->category, '2026-11-21 20:30', event: ['title' => 'Trappola @@sponsor-token:'.$concorrente->getKey().':qualsiasi_cosa@@ @@sponsor-fill:0@@']);
    occurrenceAtLocal($this->city, $this->category, '2026-11-21 20:45', event: ['title' => 'Trappola @@sponsor-slot:'.$buco.'@@ @@live-now@@ @@home-next@@']);

    foreach (['miss', 'hit', 'hit'] as $stato) {
        $risposta = $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', $stato);
        $html = (string) $risposta->getContent();
        $validi = gettoniValidiOvunque($risposta);

        /* Validi sono solo il gettone della card in cima e quello copiato, che
           resta identico: nessun gettone nuovo per il concorrente, nessuna
           collocazione inventata. */
        expect(array_values(array_unique(array_map(fn ($c): string => $c->sponsorshipId.':'.$c->placement.':'.$c->nonce, $validi))))
            ->toEqualCanonicalizing([$alfa->getKey().':list_top:'.collect($validi)->firstWhere('sponsorshipId', $alfa->getKey())?->nonce, $concorrente->getKey().':home_card:abcdefabcdefabcd'])
            ->and(collect($validi)->where('sponsorshipId', $alfa->getKey()))->toHaveCount(1)
            ->and($html)->not->toContain('qualsiasi_cosa.')
            /* Il buco del titolo non diventa una card né un segno vuoto. */
            ->and($html)->not->toContain('Concorrente')
            ->and(substr_count($html, 'data-sponsorship-vacant'))->toBe(0)
            ->and(substr_count($html, '<!-- page-cache-sponsor:start'))->toBe(1)
            /* I marcatori restano testo, spento. */
            ->and($html)->toContain('&#64;@sponsor-slot:')
            ->and($html)->toContain('&#64;@sponsor-fill:0@@');
    }

    expect(copiaSalvata())->toContain('&#64;@live-now@@')->toContain('&#64;@home-next@@')
        ->and(substr_count(copiaSalvata(), '@@sponsor-slot:'))->toBe(1);
});

it('non firma i segnaposto scritti nel titolo di un evento che finisce nella card della home', function (): void {
    $concorrente = campagnaInCache(SponsorshipPlacement::ListTop, 'Concorrente');
    $date = occurrenceAtLocal($this->city, $this->category, '2026-11-22 21:00', event: [
        'title' => 'Card @@sponsor-token:'.$concorrente->getKey().':qualsiasi_cosa@@ v1.'.$concorrente->getKey().'.list_top.1.0000000000000000.'.str_repeat('B', 43),
    ]);
    $card = Sponsorship::factory()->placement(SponsorshipPlacement::HomeCard)->create([
        'city_id' => $this->city->getKey(), 'event_id' => $date->event_id, 'advertiser_name' => 'Consiglio',
    ]);

    foreach (['miss', 'hit'] as $stato) {
        $risposta = $this->get('/')->assertOk()->assertHeader('X-Page-Cache', $stato);

        expect(campagneInPagina($risposta))->toContain($card->getKey())
            ->and(array_unique(array_map(fn ($c): int => $c->sponsorshipId, gettoniValidiOvunque($risposta))))->not->toContain($concorrente->getKey())
            ->and((string) $risposta->getContent())->toContain('&#64;@sponsor-token:');
    }
});

it('disegna in anticipo poche campagne per un elenco filtrato mai visto', function (): void {
    foreach (range(1, 30) as $n) {
        campagnaInCache(SponsorshipPlacement::ListTop, 'Etichetta '.$n, CarbonImmutable::parse('2026-11-21 21:00')->addDays($n % 9)->format('Y-m-d H:i'));
    }

    $this->get('/eventi')->assertOk()->assertHeader('X-Page-Cache', 'miss');

    $query = 0;
    DB::listen(function () use (&$query): void {
        $query++;
    });

    $this->get('/eventi?outdoor=1')->assertOk()->assertHeader('X-Page-Cache', 'miss');
    $fuoriFiltri = $query;
    $query = 0;
    $this->get('/eventi?category='.$this->category->slug)->assertOk()->assertHeader('X-Page-Cache', 'miss');
    $neiFiltri = $query;

    /* Prima erano circa 460: trenta frammenti, tredici query ciascuno. Fuori
       dai filtri i frammenti sono quelli dell'elenco intero, già pronti; nei
       filtri se ne preparano tre, caricati insieme. */
    expect($fuoriFiltri)->toBeLessThan(80)
        ->and($neiFiltri)->toBeLessThan(110);
});
