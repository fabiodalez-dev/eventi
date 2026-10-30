<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\SearchMiss;
use App\Models\Venue;
use App\Services\Search\SearchMissLog;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Route;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * Le pagine pubbliche che costano a ogni richiesta anonima: ricerca completa,
 * locandina PDF, registro delle ricerche senza risultati e home dell'app.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('mette un tetto alla ricerca completa del sito', function (): void {
    for ($i = 0; $i < 30; $i++) {
        $this->get(route('search', ['q' => 'zzz'.$i]))->assertOk();
    }

    $this->get(route('search', ['q' => 'zzz-ancora']))->assertStatus(429);
});

it('applica il tetto della ricerca e della locandina anche agli indirizzi con la città', function (): void {
    foreach (['search', 'city.search'] as $nome) {
        expect(Route::getRoutes()->getByName($nome)?->gatherMiddleware())->toContain('throttle:search');
    }
    foreach (['events.poster', 'city.events.poster'] as $nome) {
        expect(Route::getRoutes()->getByName($nome)?->gatherMiddleware())->toContain('throttle:30,1,event-poster');
    }
});

it('mette lo stesso tetto alla ricerca libera negli elenchi degli eventi', function (): void {
    for ($i = 0; $i < 30; $i++) {
        $this->get(route('events.index', ['q' => 'zzz'.$i]))->assertOk();
    }

    $this->get(route('events.today', ['q' => 'zzz-ancora']))->assertStatus(429);
    /* Il secchio è uno solo: chi l'ha finito sugli elenchi l'ha finito anche su /cerca. */
    $this->get(route('search', ['q' => 'zzz-cerca']))->assertStatus(429);
    /* Senza `q` l'elenco resta libero: è quello che serve la cache. */
    $this->get(route('events.index'))->assertOk();
});

it('dà ai marcatori della mappa un contatore suo, che non consuma la ricerca', function (): void {
    for ($i = 0; $i < 30; $i++) {
        $this->getJson(route('map.markers', ['q' => 'zzz'.$i]))->assertOk();
    }

    $this->get(route('search', ['q' => 'zzz-cerca']))->assertOk();
    for ($i = 30; $i < 90; $i++) {
        $this->getJson(route('map.markers', ['q' => 'zzz'.$i]))->assertOk();
    }
    $this->getJson(route('map.markers', ['q' => 'zzz-ancora']))->assertStatus(429);
});

it('applica il tetto della ricerca a tutti gli elenchi degli eventi, anche con la città', function (): void {
    foreach (['events.index', 'events.today', 'events.tomorrow', 'events.weekend', 'events.free', 'events.category', 'events.tag', 'events.date', 'feeds.rss', 'map.markers', 'venues.index', 'organizers.index'] as $nome) {
        foreach ([$nome, 'city.'.$nome] as $rotta) {
            expect(Route::getRoutes()->getByName($rotta)?->gatherMiddleware())->toContain('throttle:search');
        }
    }
});

it('conta la ricerca libera sul /64 quando l indirizzo è IPv6', function (): void {
    for ($i = 1; $i <= 30; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::'.dechex($i)])
            ->get(route('events.index', ['q' => 'zzz'.$i]))->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:ffff::1'])
        ->get(route('events.index', ['q' => 'zzz-ancora']))->assertStatus(429);
    /* Un altro /64 ha il proprio secchio. */
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])
        ->get(route('events.index', ['q' => 'zzz-altro']))->assertOk();
});

it('conta le ricerche senza risultati sul /64 quando l indirizzo è IPv6', function (): void {
    $registro = app(SearchMissLog::class);
    $tetto = SearchMissLog::PER_ACTOR_HOURLY;

    /* Un indirizzo diverso dello stesso /64 a ogni ricerca. */
    for ($i = 1; $i <= $tetto + 5; $i++) {
        $registro->record($this->city, 'parola'.$i, '2001:db8:5:6::'.dechex($i));
    }

    expect(SearchMiss::query()->count())->toBe($tetto);
});

it('registra al massimo un numero fisso di ricerche senza risultati per indirizzo', function (): void {
    $tetto = SearchMissLog::PER_ACTOR_HOURLY;

    /* Un identificativo di installazione nuovo a ogni giro: è il gesto che
       aggira il limite generale dell'API. */
    for ($i = 0; $i < $tetto + 5; $i++) {
        $this->withHeaders(['X-Installation-ID' => 'installazione-finta-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)])
            ->getJson('/api/v1/search?q=parola'.bin2hex(random_bytes(6)))
            ->assertOk();
    }

    expect(SearchMiss::query()->count())->toBe($tetto);

    /* Un altro indirizzo registra ancora: il tetto è di chi cerca, non del registro. */
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
        ->getJson('/api/v1/search?q=altraparola')
        ->assertOk();

    expect(SearchMiss::query()->count())->toBe($tetto + 1);
});

it('pota le ricerche senza risultati vecchie e rare, e tiene le altre', function (): void {
    $vecchia = CarbonImmutable::now()->subDays(100);
    $rara = SearchMiss::query()->create(['city_id' => $this->city->id, 'term' => 'rara', 'count' => 1, 'first_searched_at' => $vecchia, 'last_searched_at' => $vecchia]);
    $richiesta = SearchMiss::query()->create(['city_id' => $this->city->id, 'term' => 'richiesta', 'count' => 5, 'first_searched_at' => $vecchia, 'last_searched_at' => $vecchia]);
    $recente = SearchMiss::query()->create(['city_id' => $this->city->id, 'term' => 'recente', 'count' => 1, 'first_searched_at' => now(), 'last_searched_at' => now()]);

    $this->artisan('model:prune', ['--model' => SearchMiss::class])->assertSuccessful();

    expect(SearchMiss::query()->pluck('id')->all())->toEqualCanonicalizing([$richiesta->id, $recente->id])
        ->and(SearchMiss::query()->find($rara->id))->toBeNull();
});

it('non carica tutte le date future per comporre la home dell’app', function (): void {
    freezeLocal($this->city, '2026-09-10 18:00');

    $vicino = static fn (float $spostamento): Venue => Venue::factory()->approved()->create([
        'city_id' => test()->city->id,
        'lat' => 45.4064 + $spostamento,
        'lng' => 11.8768,
        'location' => new Point(45.4064 + $spostamento, 11.8768, 0),
    ]);

    /* Un evento in evidenza ogni giorno per tre mesi, nel locale più vicino. */
    $quotidiano = Event::factory()->featured()->create([
        'city_id' => $this->city->id,
        'category_id' => $this->category->id,
        'venue_id' => $vicino(0.0)->id,
        'status' => EventStatus::Published,
        'published_at' => CarbonImmutable::now('UTC'),
    ]);
    for ($giorno = 1; $giorno <= 90; $giorno++) {
        EventOccurrence::factory()->create([
            'event_id' => $quotidiano->id,
            'starts_at' => CarbonImmutable::parse('2026-09-10 19:00:00', 'UTC')->addDays($giorno),
            'ends_at' => null,
            'doors_at' => null,
        ]);
    }

    /* Altri tre eventi poco più lontani: il «vicino a te» deve mostrarli tutti. */
    $altri = collect([0.002, 0.004, 0.006])->map(fn (float $d): EventOccurrence => occurrenceAt(
        $this->city, $this->category, '2026-09-20 19:00:00', venue: $vicino($d),
    ));

    $caricate = 0;
    EventFacade::listen('eloquent.retrieved: '.EventOccurrence::class, function () use (&$caricate): void {
        $caricate++;
    });

    $risposta = $this->getJson('/api/v1/home')->assertOk();

    $vicini = collect($risposta->json('data.sections.nearby'))->pluck('event_id');
    $evidenza = collect($risposta->json('data.sections.featured'))->pluck('event_id');

    expect($caricate)->toBeLessThan(60)
        ->and($vicini->first())->toBe($quotidiano->id)
        ->and($vicini->all())->toContain(...$altri->pluck('event_id')->all())
        ->and($vicini->duplicates()->all())->toBe([])
        ->and($evidenza->all())->toBe([$quotidiano->id]);
});
