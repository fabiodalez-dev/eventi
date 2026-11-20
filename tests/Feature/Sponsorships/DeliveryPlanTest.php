<?php

declare(strict_types=1);

use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Models\Venue;
use App\Services\Sponsorship\Delivery\DeliveryPlan;
use App\Services\Sponsorship\Delivery\DeliveryPlanBuilder;
use App\Services\Sponsorship\Delivery\Draw;
use App\Services\Sponsorship\Delivery\EventWeightAdjuster;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use App\Services\Sponsorship\Delivery\QuotaAllocator;
use App\Services\Sponsorship\Delivery\SeededDraw;
use App\Services\Sponsorship\Delivery\ViewerMultiplier;
use App\Services\Sponsorship\SponsorshipProgress;
use App\Services\Sponsorship\SponsorshipSelector;
use App\Support\ContentVersion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Il piano di consegna: chi può comparire e con quale quota, per città e
 * collocazione. La prima parte prova l'estrazione con piani scritti a mano
 * (nessun database), la seconda il costruttore che li legge dal database, la
 * terza la cache e il comando che li ricostruisce ogni minuto.
 */

/**
 * Un'unità scritta a mano: una riga per evento, con id e evento uguali.
 *
 * @param  list<int>  $rows
 * @param  list<float>|null  $weights
 * @return array{key: string, priority: int, weight: int, quota: float|null, rows: list<array{id: int, event: int, slug: string, venue: int|null, category: int|null, weight: float}>}
 */
function unitaDiProva(string $key, int $weight = 1, int $priority = 0, ?float $quota = null, array $rows = [1], ?array $weights = null, ?int $venue = null): array
{
    return [
        'key' => $key,
        'priority' => $priority,
        'weight' => $weight,
        'quota' => $quota,
        'rows' => array_map(static fn (int $id, int $index): array => [
            'id' => $id, 'event' => $id, 'slug' => 'evento-'.$id, 'venue' => $venue, 'category' => null,
            'weight' => $weights[$index] ?? 1.0,
        ], $rows, array_keys($rows)),
    ];
}

/** @param  list<array<string, mixed>>  $units */
function pianoDiProva(array $units, string $placement = 'list_top'): DeliveryPlan
{
    $keyed = [];

    foreach ($units as $unit) {
        $keyed[$unit['key']] = $unit;
    }

    return new DeliveryPlan(1, $placement, 1_700_000_000, $keyed);
}

describe('estrazione', function (): void {
    it('ritrova al numero esatto le 10.000 estrazioni della simulazione', function (): void {
        $plan = pianoDiProva([
            unitaDiProva('s:1', 6, rows: [1]),
            unitaDiProva('s:2', 3, rows: [2]),
            unitaDiProva('s:3', 2, rows: [3]),
            unitaDiProva('s:4', 1, rows: [4]),
        ]);
        $draw = new SeededDraw(20261119);
        $counts = ['s:1' => 0, 's:2' => 0, 's:3' => 0, 's:4' => 0];

        for ($i = 0; $i < 10_000; $i++) {
            $counts[$plan->unitOf((int) $plan->draw($draw))]++;
        }

        expect($plan->shares())->toBe(['s:1' => 0.5, 's:2' => 0.25, 's:3' => 1 / 6, 's:4' => 1 / 12])
            ->and($plan->reserve())->toBe(0.0)
            ->and($counts)->toBe(['s:1' => 5008, 's:2' => 2468, 's:3' => 1668, 's:4' => 856]);
    });

    it('lascia al contenuto redazionale lo spazio che le unità a visualizzazioni non chiedono', function (): void {
        $plan = pianoDiProva([unitaDiProva('g:1', quota: 0.5, rows: [1, 2])]);
        $draw = new SeededDraw(7);
        $vuote = 0;

        for ($i = 0; $i < 2_000; $i++) {
            $vuote += $plan->draw($draw) === null ? 1 : 0;
        }

        /* Atteso 1.000, deviazione 22: fra 910 e 1.090 sono quattro deviazioni. */
        expect($plan->reserve())->toBe(0.5)
            ->and($vuote)->toBeGreaterThanOrEqual(910)->toBeLessThanOrEqual(1_090);
    });

    it('dà tutto alla fascia più alta e niente a quella sotto finché la sopra chiede tutto', function (): void {
        $plan = pianoDiProva([
            unitaDiProva('s:1', 1, priority: 10, rows: [1]),
            unitaDiProva('s:2', 50, priority: 1, rows: [2]),
        ]);
        $draw = new SeededDraw(1);

        $scelte = collect(range(1, 200))->map(fn (): ?int => $plan->draw($draw))->unique()->values()->all();

        expect($scelte)->toBe([1])
            ->and($plan->probabilities())->toBe(['s:1' => 1.0, 's:2' => 0.0, QuotaAllocator::RESERVE => 0.0]);
    });

    it('fa salire la fascia sotto quando quella sopra non ha niente in questa pagina', function (): void {
        $plan = pianoDiProva([
            unitaDiProva('s:1', 1, priority: 10, rows: [1]),
            unitaDiProva('s:2', 1, priority: 1, rows: [2]),
        ]);

        expect($plan->restrict(static fn (array $row): bool => $row['id'] !== 1)->shares())->toBe(['s:2' => 1.0])
            ->and($plan->shares())->toBe(['s:1' => 1.0, 's:2' => 0.0]);
    });

    it('preferisce le unità con un evento che rispetta i filtri e torna a tutte se non ce n è', function (): void {
        $plan = pianoDiProva([
            unitaDiProva('g:1', 1, priority: 10, rows: [1, 2]),
            unitaDiProva('s:3', 1, priority: 0, rows: [3]),
        ]);
        $draw = new SeededDraw(3);

        $conFiltro = collect(range(1, 100))->map(fn (): ?int => $plan->draw($draw, [], [3]))->unique()->values()->all();
        $soloUnEvento = collect(range(1, 100))->map(fn (): ?int => $plan->draw($draw, [], [2]))->unique()->values()->all();
        $nessuno = collect(range(1, 100))->map(fn (): ?int => $plan->draw($draw, [], [99]))->unique()->sort()->values()->all();

        expect($conFiltro)->toBe([3])
            ->and($soloUnEvento)->toBe([2])
            ->and($nessuno)->toBe([1, 2]);
    });

    it('sceglie l evento dentro l unità con il suo peso', function (): void {
        /* 62% / 31% / 7%: i pesi del documento per eventi fra 1, 6 e 20 giorni. */
        $plan = pianoDiProva([unitaDiProva('g:1', rows: [1, 2, 3], weights: [0.867, 0.424, 0.1])]);
        $draw = new SeededDraw(20261119);
        $counts = [1 => 0, 2 => 0, 3 => 0];

        for ($i = 0; $i < 10_000; $i++) {
            $counts[(int) $plan->draw($draw)]++;
        }

        expect($counts[1] / 10_000)->toEqualWithDelta(0.62, 0.015)
            ->and($counts[2] / 10_000)->toEqualWithDelta(0.30, 0.015)
            ->and($counts[3] / 10_000)->toEqualWithDelta(0.07, 0.01);
    });

    it('porta l affinità dagli eventi all unità e la usa come moltiplicatore', function (): void {
        /* L'esempio completo del documento: A a visualizzazioni 0,50, C e D a
           periodo con pesi 2 e 1; chi segue il locale di D ha a = 4.
           Probabilità: A 33%, C 22%, D 44%. */
        $plan = pianoDiProva([
            unitaDiProva('s:1', quota: 0.5, rows: [1]),
            unitaDiProva('s:2', 2, rows: [2]),
            unitaDiProva('s:3', 1, rows: [3]),
        ]);

        $multipliers = $plan->unitMultipliers([3 => 4.0]);
        $probabilities = $plan->probabilities($multipliers);

        expect($multipliers)->toBe(['s:1' => 1.0, 's:2' => 1.0, 's:3' => 4.0])
            ->and($probabilities['s:1'])->toEqualWithDelta(1 / 3, 1e-9)
            ->and($probabilities['s:2'])->toEqualWithDelta(2 / 9, 1e-9)
            ->and($probabilities['s:3'])->toEqualWithDelta(4 / 9, 1e-9)
            ->and(array_sum($probabilities))->toEqualWithDelta(1.0, 1e-9);
    });

    it('media l affinità sulle quote degli eventi dell unità', function (): void {
        $plan = pianoDiProva([unitaDiProva('g:1', rows: [1, 2], weights: [3.0, 1.0])]);

        expect($plan->unitMultipliers([1 => 4.0]))->toBe(['g:1' => 3.25]);
    });

    it('chiede il secondo numero solo per un unità con più eventi', function (): void {
        $uno = pianoDiProva([unitaDiProva('s:1', rows: [1]), unitaDiProva('s:2', rows: [2])]);
        $draw = new SeededDraw(11);
        $riferimento = new SeededDraw(11);

        $scelte = array_map(fn (): ?int => $uno->draw($draw), range(1, 50));
        $attese = array_map(fn (): int => $riferimento->fraction() < 0.5 ? 1 : 2, range(1, 50));

        expect($scelte)->toBe($attese);
    });

    it('divide in parti uguali un unità i cui eventi pesano tutti zero', function (): void {
        $plan = pianoDiProva([unitaDiProva('g:1', rows: [1, 2], weights: [0.0, 0.0])]);

        expect($plan->eventShares($plan->units()['g:1']))->toBe([1 => 0.5, 2 => 0.5]);
    });

    it('non estrae niente da un piano vuoto', function (): void {
        $plan = DeliveryPlan::empty(1, 'list_top', 0);

        expect($plan->draw(new SeededDraw(1)))->toBeNull()
            ->and($plan->isEmpty())->toBeTrue()
            ->and($plan->reserve())->toBe(1.0);
    });

    it('si salva in cache come array e si rilegge uguale', function (): void {
        $plan = pianoDiProva([unitaDiProva('g:1', 3, 5, 0.4, [1, 2]), unitaDiProva('s:9', rows: [9])]);
        $letto = DeliveryPlan::fromArray($plan->toArray());

        expect($letto?->toArray())->toBe($plan->toArray())
            ->and($letto?->shares())->toBe($plan->shares())
            ->and(DeliveryPlan::fromArray(['format' => 0] + $plan->toArray()))->toBeNull()
            ->and(DeliveryPlan::fromArray('rotto'))->toBeNull()
            ->and(DeliveryPlan::fromArray(['units' => ['g:1' => unitaDiProva('g:2')]] + $plan->toArray()))->toBeNull();
    });

    it('sa quando è troppo vecchio, anche all indietro', function (): void {
        $plan = pianoDiProva([]);

        expect($plan->isFresh(1_700_000_120, 120))->toBeTrue()
            ->and($plan->isFresh(1_700_000_121, 120))->toBeFalse()
            ->and($plan->isFresh(1_699_999_879, 120))->toBeFalse();
    });
});

describe('costruzione dal database', function (): void {
    beforeEach(function (): void {
        $this->city = testCity();
        freezeLocal($this->city, '2026-11-20 10:00');
        $this->category = testCategory();
        $this->builder = app(DeliveryPlanBuilder::class);
    });

    it('raccoglie le righe di un abilitazione in un unità con peso e priorità dell abilitazione', function (): void {
        $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
        $grant = SponsorshipGrant::factory()->weighted(3, 7)->create(['venue_id' => $venue->getKey()]);

        foreach (['2026-11-21 21:00', '2026-11-26 21:00'] as $quando) {
            $date = occurrenceAtLocal($this->city, $this->category, $quando, venue: $venue);
            Sponsorship::factory()->forGrant($grant)->create(['city_id' => $this->city->getKey(), 'event_id' => $date->event_id]);
        }

        $altra = occurrenceAtLocal($this->city, $this->category, '2026-11-22 21:00');
        $singola = Sponsorship::factory()->create(['city_id' => $this->city->getKey(), 'event_id' => $altra->event_id, 'placement' => SponsorshipPlacement::ListTop, 'weight' => 2, 'priority' => 7]);

        $plan = $this->builder->build($this->city, SponsorshipPlacement::ListTop);

        expect(array_keys($plan->units()))->toBe(['s:'.$singola->getKey(), 'g:'.$grant->getKey()])
            ->and($plan->units()['g:'.$grant->getKey()]['weight'])->toBe(3)
            ->and($plan->units()['g:'.$grant->getKey()]['priority'])->toBe(7)
            ->and($plan->units()['g:'.$grant->getKey()]['rows'])->toHaveCount(2)
            ->and($plan->shares()['g:'.$grant->getKey()])->toEqualWithDelta(0.6, 1e-9)
            ->and($plan->shares()['s:'.$singola->getKey()])->toEqualWithDelta(0.4, 1e-9);
    });

    it('pesa gli eventi dell unità con la vicinanza della data: 62, 31 e 7 per cento', function (): void {
        $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
        $grant = SponsorshipGrant::factory()->create(['venue_id' => $venue->getKey()]);

        foreach (['2026-11-21 10:00', '2026-11-26 10:00', '2026-12-10 10:00'] as $quando) {
            $date = occurrenceAtLocal($this->city, $this->category, $quando, venue: $venue);
            Sponsorship::factory()->forGrant($grant)->create(['city_id' => $this->city->getKey(), 'event_id' => $date->event_id]);
        }

        $plan = $this->builder->build($this->city, SponsorshipPlacement::ListTop);
        $shares = array_values($plan->eventShares($plan->units()['g:'.$grant->getKey()]));

        expect(round($shares[0], 2))->toBe(0.62)
            ->and(round($shares[1], 2))->toBe(0.31)
            ->and(round($shares[2], 2))->toBe(0.07);
    });

    it('dà a un unità a visualizzazioni la quota che chiede il suo ritmo', function (): void {
        /* Obiettivo 1.000, nessuna consegnata, dieci giorni di cima
           dell'elenco a 200 al giorno di partenza: circa 2.000 previste,
           quota circa 0,5. Con «subito» la quota è 0,8 fissa. */
        $even = occurrenceAtLocal($this->city, $this->category, '2026-12-10 21:00');
        $uniforme = Sponsorship::factory()->goal(1_000)->create([
            'city_id' => $this->city->getKey(), 'event_id' => $even->event_id, 'placement' => SponsorshipPlacement::ListTop,
            'starts_at' => CarbonImmutable::now('UTC')->subHour(), 'ends_at' => CarbonImmutable::now('UTC')->addDays(10),
        ]);
        $asap = occurrenceAtLocal($this->city, $this->category, '2026-12-11 21:00');
        $subito = Sponsorship::factory()->goal(1_000, SponsorshipPacing::Asap)->create([
            'city_id' => $this->city->getKey(), 'event_id' => $asap->event_id, 'placement' => SponsorshipPlacement::HomeCard,
        ]);

        $lista = $this->builder->build($this->city, SponsorshipPlacement::ListTop);
        $card = $this->builder->build($this->city, SponsorshipPlacement::HomeCard);

        expect($lista->units()['s:'.$uniforme->getKey()]['quota'])->toEqualWithDelta(0.5, 0.05)
            ->and($lista->reserve())->toEqualWithDelta(1 - $lista->units()['s:'.$uniforme->getKey()]['quota'], 1e-9)
            ->and($card->units()['s:'.$subito->getKey()]['quota'])->toBe(0.8)
            ->and($card->reserve())->toEqualWithDelta(0.2, 1e-9);
    });

    it('segna a rischio chi chiede più di quanto lo spazio può dare', function (): void {
        $date = occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00');
        $troppo = Sponsorship::factory()->goal(50_000)->create([
            'city_id' => $this->city->getKey(), 'event_id' => $date->event_id, 'placement' => SponsorshipPlacement::ListTop,
            'ends_at' => CarbonImmutable::now('UTC')->addDays(30),
        ]);

        $plan = $this->builder->build($this->city, SponsorshipPlacement::ListTop);

        /* La fine vera è la fine della serata, non quella della campagna. */
        expect($plan->atRisk())->toBe(['s:'.$troppo->getKey()])
            ->and($plan->shares()['s:'.$troppo->getKey()])->toBe(0.8);
    });

    it('somma le consegnate di tutte le righe di un abilitazione, cestino compreso', function (): void {
        $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
        $grant = SponsorshipGrant::factory()->goal(1_000)->create(['venue_id' => $venue->getKey(), 'ends_at' => CarbonImmutable::now('UTC')->addDays(10)]);
        $rows = [];

        foreach (['2026-11-29 21:00', '2026-11-30 21:00'] as $quando) {
            $date = occurrenceAtLocal($this->city, $this->category, $quando, venue: $venue);
            $rows[] = Sponsorship::factory()->forGrant($grant)->create(['city_id' => $this->city->getKey(), 'event_id' => $date->event_id]);
        }

        $rows[0]->forceFill(['impressions' => 300])->saveQuietly();
        $rows[1]->forceFill(['impressions' => 100])->saveQuietly();
        $pieno = $this->builder->build($this->city, SponsorshipPlacement::ListTop)->units()['g:'.$grant->getKey()]['quota'];

        $rows[1]->delete();
        $date = occurrenceAtLocal($this->city, $this->category, '2026-11-30 21:00', venue: $venue);
        Sponsorship::factory()->forGrant($grant)->create(['city_id' => $this->city->getKey(), 'event_id' => $date->event_id]);
        $conCestino = $this->builder->build($this->city, SponsorshipPlacement::ListTop)->units()['g:'.$grant->getKey()]['quota'];

        /* 600 mancanti in entrambi i casi: la riga nel cestino conta ancora. */
        expect($conCestino)->toEqualWithDelta($pieno, 0.02)
            ->and($pieno)->toBeGreaterThan(0.0);
    });

    it('tiene fuori gli eventi senza una data valida e distingue card e banner', function (): void {
        freezeLocal($this->city, '2026-11-20 20:00');
        $finita = occurrenceAtLocal($this->city, $this->category, '2026-11-20 16:00', '2026-11-20 18:00');
        $inCorso = occurrenceAtLocal($this->city, $this->category, '2026-11-20 19:00', '2026-11-20 23:00');

        foreach ([SponsorshipPlacement::ListTop, SponsorshipPlacement::HomeCard] as $placement) {
            foreach ([$finita, $inCorso] as $date) {
                Sponsorship::factory()->create(['city_id' => $this->city->getKey(), 'event_id' => $date->event_id, 'placement' => $placement]);
            }
        }

        $lista = $this->builder->build($this->city, SponsorshipPlacement::ListTop)->eventIds();
        $card = $this->builder->build($this->city, SponsorshipPlacement::HomeCard)->eventIds();
        $banner = $this->builder->build($this->city, DeliveryPlanBuilder::BANNER)->eventIds();

        /* La cima dell'elenco mostra la serata in corso, la card no (mostra
           solo date che devono ancora cominciare); il banner, come
           `visible()`, tiene anche la serata finita fino a mezzanotte, e un
           evento una volta sola. */
        expect($lista)->toBe([$inCorso->event_id])
            ->and($card)->toBe([])
            ->and($banner)->toEqualCanonicalizing([$finita->event_id, $inCorso->event_id])
            ->and($this->builder->build($this->city, DeliveryPlanBuilder::BANNER)->rows())->toHaveCount(2);
    });

    it('usa il punto di estensione del peso degli eventi', function (): void {
        $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
        $grant = SponsorshipGrant::factory()->create(['venue_id' => $venue->getKey()]);
        $rows = [];

        foreach (['2026-11-21 21:00', '2026-11-22 21:00'] as $quando) {
            $date = occurrenceAtLocal($this->city, $this->category, $quando, venue: $venue);
            $rows[] = Sponsorship::factory()->forGrant($grant)->create(['city_id' => $this->city->getKey(), 'event_id' => $date->event_id]);
        }

        app()->instance(EventWeightAdjuster::class, new class implements EventWeightAdjuster
        {
            public function adjust(string $unitKey, array $weights, CarbonImmutable $now): array
            {
                return array_map(static fn (float $weight): float => 0.0, $weights) + [];
            }
        });
        $azzerati = app(DeliveryPlanBuilder::class)->build($this->city, SponsorshipPlacement::ListTop);

        expect(array_column($azzerati->rows(), 'weight'))->toBe([0.0, 0.0]);
    });
});

describe('cache e comando', function (): void {
    beforeEach(function (): void {
        Cache::flush();
        $this->city = testCity();
        freezeLocal($this->city, '2026-11-20 10:00');
        $this->category = testCategory();
        $date = occurrenceAtLocal($this->city, $this->category, '2026-11-21 21:00');
        $this->campaign = Sponsorship::factory()->create(['city_id' => $this->city->getKey(), 'event_id' => $date->event_id, 'placement' => SponsorshipPlacement::ListTop]);
    });

    it('rilegge il piano dalla cache senza nessuna query', function (): void {
        $builder = app(DeliveryPlanBuilder::class);
        $builder->current($this->city, SponsorshipPlacement::ListTop);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $plan = $builder->current((int) $this->city->getKey(), SponsorshipPlacement::ListTop);

        expect(DB::getQueryLog())->toBe([])
            ->and($plan->rowIds())->toBe([$this->campaign->getKey()]);
    });

    it('lo ricostruisce quando è più vecchio di due minuti o cambia la versione dei contenuti', function (): void {
        $builder = app(DeliveryPlanBuilder::class);
        $primo = $builder->current($this->city, SponsorshipPlacement::ListTop);

        Sponsorship::query()->whereKey($this->campaign->getKey())->update(['status' => 'paused']);

        $this->travel(119)->seconds();
        expect($builder->current($this->city, SponsorshipPlacement::ListTop)->builtAt())->toBe($primo->builtAt());

        $this->travel(2)->seconds();
        expect($builder->current($this->city, SponsorshipPlacement::ListTop)->isEmpty())->toBeTrue();

        Sponsorship::query()->whereKey($this->campaign->getKey())->update(['status' => 'active']);
        ContentVersion::bump($this->city);
        expect($builder->current($this->city, SponsorshipPlacement::ListTop)->rowIds())->toBe([$this->campaign->getKey()]);
    });

    it('il comando ricostruisce un piano per ogni collocazione e per il banner', function (): void {
        Artisan::call('sponsorships:plan');

        foreach (DeliveryPlanBuilder::places() as $place) {
            expect(DeliveryPlan::fromArray(Cache::get(DeliveryPlanBuilder::cacheKey((int) $this->city->getKey(), $place))))->not->toBeNull();
        }

        expect(Artisan::output())->toContain('Piani ricostruiti: 5');
    });

    it('il comando costruisce i piani di una città con 50 unità in meno di due secondi', function (): void {
        $venues = Venue::factory()->approved()->count(25)->create(['city_id' => $this->city->getKey()]);

        foreach ($venues as $index => $venue) {
            $grant = SponsorshipGrant::factory()->weighted(1 + $index % 3)->create(['venue_id' => $venue->getKey()]);

            foreach ([1, 2] as $giorno) {
                $date = occurrenceAtLocal($this->city, $this->category, '2026-11-2'.$giorno.' 21:00', venue: $venue);
                Sponsorship::factory()->forGrant($grant)->create(['city_id' => $this->city->getKey(), 'event_id' => $date->event_id]);
            }
        }

        foreach (range(1, 24) as $giorno) {
            $date = occurrenceAtLocal($this->city, $this->category, '2026-11-25 21:00');
            Sponsorship::factory()->goal(500)->create(['city_id' => $this->city->getKey(), 'event_id' => $date->event_id, 'placement' => SponsorshipPlacement::ListTop]);
        }

        $inizio = hrtime(true);
        Artisan::call('sponsorships:plan', ['--city' => $this->city->getKey()]);
        $secondi = (hrtime(true) - $inizio) / 1e9;

        $plan = DeliveryPlan::fromArray(Cache::get(DeliveryPlanBuilder::cacheKey((int) $this->city->getKey(), SponsorshipPlacement::ListTop)));

        expect($plan?->units())->toHaveCount(50)
            ->and($secondi)->toBeLessThan(2.0);
    });

    it('è nello scheduler ogni minuto, senza sovrapposizioni', function (): void {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($evento): bool => str_contains((string) $evento->command, 'sponsorships:plan'));

        expect($evento)->not->toBeNull()
            ->and($evento->expression)->toBe('* * * * *')
            ->and($evento->withoutOverlapping)->toBeTrue();
    });
});

describe('chi guarda', function (): void {
    beforeEach(function (): void {
        $this->city = testCity();
        freezeLocal($this->city, '2026-11-20 10:00');
        $this->category = testCategory();
    });

    it('il foglio della mappa mostra solo una campagna di quel locale', function (): void {
        $qui = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
        $altrove = occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00');
        $locale = occurrenceAtLocal($this->city, $this->category, '2026-11-20 22:00', venue: $qui);
        Sponsorship::factory()->placement(SponsorshipPlacement::MapSheet)->create(['city_id' => $this->city->getKey(), 'event_id' => $altrove->event_id, 'priority' => 100]);
        $giusta = Sponsorship::factory()->placement(SponsorshipPlacement::MapSheet)->create(['city_id' => $this->city->getKey(), 'event_id' => $locale->event_id, 'advertiser_name' => 'Etichetta del circolo']);

        $this->get(route('map.venue', ['venue' => $qui->getKey()]))->assertOk()
            ->assertViewHas('sponsorship', fn (?Sponsorship $scelta): bool => $scelta?->getKey() === $giusta->getKey())
            ->assertSee('Etichetta del circolo')
            ->assertSee('data-sponsorship-token="v1.'.$giusta->getKey().'.map_sheet.', false);

        $this->get(route('map.venue', ['venue' => $altrove->event->venue_id]))->assertOk()
            ->assertViewHas('sponsorship', fn (?Sponsorship $scelta): bool => $scelta?->event_id === $altrove->event_id);
    });

    it('a chi è collegato non mostra un locale che ha nascosto', function (): void {
        $nascosto = occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00');
        $visibile = occurrenceAtLocal($this->city, $this->category, '2026-11-21 21:00');
        Sponsorship::factory()->create(['city_id' => $this->city->getKey(), 'event_id' => $nascosto->event_id, 'placement' => SponsorshipPlacement::ListTop, 'priority' => 100]);
        $resta = Sponsorship::factory()->create(['city_id' => $this->city->getKey(), 'event_id' => $visibile->event_id, 'placement' => SponsorshipPlacement::ListTop]);
        $utente = User::factory()->create(['content_preferences' => ['hidden_venues' => [$nascosto->event->venue_id]]]);

        $selettore = app(SponsorshipSelector::class)->withDraw(new SeededDraw(5));

        expect(collect(range(1, 20))->map(fn (): ?int => $selettore->first($this->city, SponsorshipPlacement::ListTop, user: $utente)?->getKey())->unique()->all())
            ->toBe([$resta->getKey()]);
    });

    it('usa il punto di estensione del moltiplicatore per chi guarda', function (): void {
        $a = occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00');
        $b = occurrenceAtLocal($this->city, $this->category, '2026-11-21 21:00');
        $spenta = Sponsorship::factory()->create(['city_id' => $this->city->getKey(), 'event_id' => $a->event_id, 'placement' => SponsorshipPlacement::ListTop]);
        $accesa = Sponsorship::factory()->create(['city_id' => $this->city->getKey(), 'event_id' => $b->event_id, 'placement' => SponsorshipPlacement::ListTop]);

        app()->instance(ViewerMultiplier::class, new class($spenta->unitKey()) implements ViewerMultiplier
        {
            public function __construct(private readonly string $key) {}

            public function multipliers(DeliveryPlan $plan, ?User $user, CarbonImmutable $now): array
            {
                return [$this->key => 0.0];
            }
        });

        $selettore = app(SponsorshipSelector::class)->withDraw(new SeededDraw(9));

        expect(collect(range(1, 20))->map(fn (): ?int => $selettore->first($this->city, SponsorshipPlacement::ListTop)?->getKey())->unique()->all())
            ->toBe([$accesa->getKey()]);
    });
});

describe('consegna fra città, banner e fine vera', function (): void {
    beforeEach(function (): void {
        $this->city = testCity();
        freezeLocal($this->city, '2026-11-20 10:00');
        $this->category = testCategory();
        $this->builder = app(DeliveryPlanBuilder::class);
    });

    it('tiene le unità a visualizzazioni fuori dal banner, ma non l evento sponsorizzato anche a periodo', function (): void {
        $entrambe = occurrenceAtLocal($this->city, $this->category, '2026-11-22 21:00');
        $soloObiettivo = occurrenceAtLocal($this->city, $this->category, '2026-11-23 21:00');
        Sponsorship::factory()->goal(1_000)->create(['city_id' => $this->city->getKey(), 'event_id' => $entrambe->event_id, 'placement' => SponsorshipPlacement::ListTop, 'priority' => 50]);
        $periodo = Sponsorship::factory()->create(['city_id' => $this->city->getKey(), 'event_id' => $entrambe->event_id, 'placement' => SponsorshipPlacement::HomeCard]);
        Sponsorship::factory()->goal(1_000)->create(['city_id' => $this->city->getKey(), 'event_id' => $soloObiettivo->event_id, 'placement' => SponsorshipPlacement::HomeCard]);

        $banner = $this->builder->build($this->city, DeliveryPlanBuilder::BANNER);

        /* Le visualizzazioni del banner finirebbero nell'obiettivo senza ritmo. */
        expect($banner->rowIds())->toBe([$periodo->getKey()]);
    });

    it('spalma l obiettivo di un abilitazione presente in due città sulla somma delle due', function (): void {
        $verona = City::factory()->create(['timezone' => 'Europe/Rome']);
        $organizzatore = Organizer::query()->create(['city_id' => $this->city->getKey(), 'name' => 'Collettivo in due città', 'is_active' => true]);
        $grant = SponsorshipGrant::factory()->forOrganizer($organizzatore)->goal(3_000)->create([
            'starts_at' => CarbonImmutable::now('UTC')->subHour(), 'ends_at' => CarbonImmutable::now('UTC')->addDays(10),
        ]);

        foreach ([$this->city, $verona] as $citta) {
            $date = occurrenceAtLocal($citta, $this->category, '2026-12-05 21:00', event: ['organizer_id' => $organizzatore->getKey()]);
            Sponsorship::factory()->forGrant($grant)->create(['city_id' => $citta->getKey(), 'event_id' => $date->event_id]);
        }

        $padova = $this->builder->build($this->city, SponsorshipPlacement::ListTop)->units()['g:'.$grant->getKey()]['quota'];
        $altra = $this->builder->build($verona, SponsorshipPlacement::ListTop)->units()['g:'.$grant->getKey()]['quota'];
        $progresso = app(SponsorshipProgress::class)->forGrant($grant->refresh());

        /* Dieci giorni a 200 al giorno di partenza per città: 4.000 previste in
           tutto, quota 0,75 in ognuna. Prima ogni città chiedeva da sola tutto
           il mancante (3.000 su 2.000): 1,5, a rischio, al doppio del ritmo. */
        expect($padova)->toEqualWithDelta(0.75, 0.05)
            ->and($altra)->toEqualWithDelta($padova, 1e-9)
            ->and($progresso->quota)->toEqualWithDelta($padova, 0.01)
            ->and($progresso->forecast->dailyAverage())->toEqualWithDelta(400.0, 1e-6);
    });

    it('per la card della home chiude la consegna all inizio dell ultima data, come il pannello', function (): void {
        /* Una mostra di quattro giorni: la card la mostra solo finché non è
           cominciata, quindi la fine vera è l'inizio, non la fine. */
        $mostra = occurrenceAtLocal($this->city, $this->category, '2026-11-25 10:00', '2026-11-29 19:00');
        $unita = Sponsorship::factory()->goal(500)->create([
            'city_id' => $this->city->getKey(), 'event_id' => $mostra->event_id, 'placement' => SponsorshipPlacement::HomeCard,
            'starts_at' => CarbonImmutable::now('UTC')->subHour(), 'ends_at' => CarbonImmutable::now('UTC')->addDays(12),
        ]);

        $quota = $this->builder->build($this->city, SponsorshipPlacement::HomeCard)->units()['s:'.$unita->getKey()]['quota'];
        $progresso = app(SponsorshipProgress::class)->forRow($unita->refresh());
        $previste = $progresso->forecast->between(CarbonImmutable::now('Europe/Rome'), localInstant($this->city, '2026-11-25 10:00'));

        expect($progresso->trueEnd->equalTo(localInstant($this->city, '2026-11-25 10:00')))->toBeTrue()
            ->and($quota)->toEqualWithDelta(500 / $previste, 1e-6)
            ->and($progresso->quota)->toEqualWithDelta($quota, 1e-6);
    });
});

describe('il foglio della mappa', function (): void {
    beforeEach(function (): void {
        $this->city = testCity();
        freezeLocal($this->city, '2026-11-20 10:00');
        $this->category = testCategory();
    });

    it('mostra sempre l unità del locale, anche a visualizzazioni, senza lasciare il foglio alla riserva', function (): void {
        $qui = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
        $date = occurrenceAtLocal($this->city, $this->category, '2026-11-24 21:00', venue: $qui);
        $unita = Sponsorship::factory()->goal(100)->placement(SponsorshipPlacement::MapSheet)->create([
            'city_id' => $this->city->getKey(), 'event_id' => $date->event_id,
            'starts_at' => CarbonImmutable::now('UTC')->subHour(), 'ends_at' => CarbonImmutable::now('UTC')->addDays(10),
        ]);
        app()->instance(Draw::class, new SeededDraw(7));

        /* Sul piano della città l'unità ha una quota (circa la metà): prima il
           foglio del suo locale restava vuoto per l'altra metà delle aperture. */
        expect(app(DeliveryPlanBuilder::class)->build($this->city, SponsorshipPlacement::MapSheet)->reserve())->toBeGreaterThan(0.2);

        $mostrate = collect(range(1, 20))->filter(fn (): bool => app(SponsorshipSelector::class)
            ->atVenue($this->city, SponsorshipPlacement::MapSheet, (int) $qui->getKey())?->getKey() === $unita->getKey());

        expect($mostrate)->toHaveCount(20);
    });

    it('segna con il suo gettone il foglio che non mostra nessuna sponsorizzazione', function (): void {
        $qui = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
        occurrenceAtLocal($this->city, $this->category, '2026-11-24 21:00', venue: $qui);

        $html = (string) $this->get(route('map.venue', ['venue' => $qui->getKey()]))->assertOk()->getContent();

        preg_match('/data-sponsorship-opportunity-token="([^"]+)"/', $html, $gettone);

        expect($html)->toContain('data-sponsorship-opportunity="map_sheet"')
            ->and(app(ImpressionToken::class)->verifyOpportunity($gettone[1] ?? '', CarbonImmutable::now()))
            ->toMatchArray(['city' => (int) $this->city->getKey(), 'placement' => 'map_sheet']);
    });
});
