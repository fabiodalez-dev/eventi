<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Http\Resources\V1\SponsorshipProgressResource;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipGrant;
use App\Models\Venue;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('consegna in una richiesta le sezioni e i dati necessari alla home Android', function (): void {
    $occurrence = occurrenceAt(
        $this->city,
        $this->category,
        '2026-09-12 19:00:00',
        event: ['title' => 'Sabato in città'],
    );

    Sponsorship::factory()->placement(SponsorshipPlacement::HomeHero)->create([
        'event_id' => $occurrence->event_id,
        'city_id' => $this->city->getKey(),
        'advertiser_name' => 'Partner trasparente',
    ]);

    $response = $this->getJson('/api/v1/home')->assertOk();

    expect($response->json('data.city'))->toBe($this->city->slug)
        ->and(collect($response->json('data.sections.weekend'))->pluck('occurrence_id')->all())
        ->toContain((int) $occurrence->getKey())
        ->and($response->json('data.sponsorships.hero.label'))->toBeString()
        ->and($response->json('data.sponsorships.hero.advertiser.name'))->toBe('Partner trasparente')
        ->and($response->json('data.stats.upcoming'))->toBeGreaterThanOrEqual(1)
        ->and($response->json('data.map.markers'))->not->toBeEmpty();
});

it('espone sponsorizzazioni dichiarate e deduplica le metriche del client', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $campaign = Sponsorship::factory()->placement(SponsorshipPlacement::ListTop)->create([
        'event_id' => $occurrence->event_id,
        'city_id' => $this->city->getKey(),
        'advertiser_name' => 'Sponsor corretto',
    ]);

    $response = $this->getJson('/api/v1/sponsorships?placement=list_top')->assertOk();
    $token = $response->json('data.0.metric_token');

    expect($response->json('data.0.label'))->toBeString()
        ->and($response->json('data.0.advertiser.name'))->toBe('Sponsor corretto')
        ->and($response->json('data.0.occurrence.occurrence_id'))->toBe((int) $occurrence->getKey())
        ->and($token)->toBeString();

    $headers = [
        'X-Metric-Token' => $token,
        'X-Installation-ID' => 'android-installation-0001',
    ];

    $url = '/api/v1/reports/sponsorships/'.$campaign->getKey().'/metrics/impressions';
    $this->withHeaders($headers)->postJson($url)->assertNoContent();
    $this->withHeaders($headers)->postJson($url)->assertNoContent();

    expect((int) $campaign->refresh()->impressions)->toBe(1)
        ->and(SponsorshipDailyStat::query()->where('sponsorship_id', $campaign->getKey())->value('impressions'))->toBe(1);

    $this->withHeaders([...$headers, 'X-Metric-Token' => 'non-valido'])
        ->postJson($url)
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'INVALID_TOKEN');
});

/*
 * ---------------------------------------------------------------------------
 * Gettone usa e getta e avanzamento (piano sponsorizzazioni, pacchetto P6).
 *
 * Le risposte dell'API possono solo guadagnare campi: un'app già installata
 * legge quelli di oggi e non deve trovarne uno sparito, rinominato o di un
 * altro tipo. La fotografia di oggi è in
 * `tests/Fixtures/api/sponsorship-responses-2026-11-19.json`, presa dal codice
 * del 19/11/2026 prima di qualunque modifica, con gli stessi dati che il test
 * qui sotto ricrea.
 * ---------------------------------------------------------------------------
 */

/**
 * La forma di una risposta: per ogni campo il suo tipo, e per gli elenchi la
 * forma del primo elemento.
 */
function p6Forma(mixed $value): mixed
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            return $value === [] ? 'list' : ['list' => p6Forma($value[0])];
        }

        return array_map(p6Forma(...), $value);
    }

    return get_debug_type($value);
}

/**
 * La forma come elenco di percorsi: `home.hero.advertiser.name => string`.
 *
 * @return array<string, string>
 */
function p6Percorsi(mixed $shape, string $prefix = ''): array
{
    if (! is_array($shape)) {
        return [$prefix => (string) $shape];
    }

    $paths = [];
    foreach ($shape as $key => $child) {
        $paths += p6Percorsi($child, $prefix === '' ? (string) $key : $prefix.'.'.$key);
    }

    return $paths;
}

it('aggiunge soltanto campi alle risposte di oggi: nessuno sparito, rinominato o di un altro tipo', function (): void {
    // Il sottotitolo la fabbrica lo mette a caso: qui no, perché la fotografia ha `null`.
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00', event: ['subtitle' => null]);
    foreach ([SponsorshipPlacement::HomeHero, SponsorshipPlacement::HomeCard, SponsorshipPlacement::ListTop] as $placement) {
        Sponsorship::factory()->placement($placement)->create([
            'event_id' => $occurrence->event_id,
            'city_id' => $this->city->getKey(),
            'advertiser_url' => 'https://example.test',
        ]);
    }

    $today = p6Percorsi([
        'home' => p6Forma($this->getJson('/api/v1/home')->assertOk()->json('data.sponsorships')),
        'sponsorships' => p6Forma($this->getJson('/api/v1/sponsorships?placement=list_top')->assertOk()->json()),
        'banner' => p6Forma($this->getJson('/api/v1/sponsorships/banner?platform=android')->assertOk()->json()),
    ]);
    $snapshot = p6Percorsi(json_decode((string) file_get_contents(base_path('tests/Fixtures/api/sponsorship-responses-2026-11-19.json')), true, flags: JSON_THROW_ON_ERROR));

    expect($snapshot)->toHaveCount(213);

    foreach ($snapshot as $path => $type) {
        expect($today)->toHaveKey($path);
        expect($today[$path])->toBe($type, "Il campo {$path} ha cambiato tipo");
    }

    expect(array_keys(array_diff_key($today, $snapshot)))->toEqualCanonicalizing([
        'home.hero.impression_token',
        'home.card.impression_token',
        'sponsorships.data.list.impression_token',
        'banner.data.impression_token',
    ]);
});

it('dà a ogni risposta un gettone nuovo, firmato per la riga e la collocazione', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $hero = Sponsorship::factory()->placement(SponsorshipPlacement::HomeHero)->create(['event_id' => $occurrence->event_id, 'city_id' => $this->city->getKey()]);
    $card = Sponsorship::factory()->placement(SponsorshipPlacement::HomeCard)->create(['event_id' => $occurrence->event_id, 'city_id' => $this->city->getKey()]);
    $list = Sponsorship::factory()->placement(SponsorshipPlacement::ListTop)->create(['event_id' => $occurrence->event_id, 'city_id' => $this->city->getKey()]);
    $tokens = app(ImpressionToken::class);

    $first = $this->getJson('/api/v1/sponsorships?placement=list_top')->assertOk();
    $second = $this->getJson('/api/v1/sponsorships?placement=list_top')->assertOk();
    $claims = $tokens->verify($first->json('data.0.impression_token'), now());

    expect($claims)->not->toBeNull()
        ->and($claims->isFor($list->id, 'list_top'))->toBeTrue()
        ->and(strlen($first->json('data.0.impression_token')))->toBeLessThan(128)
        // Il gettone cambia a ogni risposta, quello giornaliero no.
        ->and($second->json('data.0.impression_token'))->not->toBe($first->json('data.0.impression_token'))
        ->and($second->json('data.0.metric_token'))->toBe($first->json('data.0.metric_token'))
        ->and($tokens->verify($second->json('data.0.impression_token'), now())?->nonce)->not->toBe($claims->nonce);

    $home = $this->getJson('/api/v1/home')->assertOk();

    expect($tokens->verify($home->json('data.sponsorships.hero.impression_token'), now())?->isFor($hero->id, 'home_hero'))->toBeTrue()
        ->and($tokens->verify($home->json('data.sponsorships.card.impression_token'), now())?->isFor($card->id, 'home_card'))->toBeTrue();
});

it('non lascia in una cache condivisa le risposte che portano un gettone usa e getta', function (): void {
    /* Senza campagne la lista resta pubblica, con il suo ETag. */
    $vuota = $this->getJson('/api/v1/sponsorships?placement=list_top')->assertOk();

    expect($vuota->headers->get('Cache-Control'))->toContain('public')
        ->and($vuota->headers->get('ETag'))->not->toBeNull();

    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    Sponsorship::factory()->placement(SponsorshipPlacement::ListTop)->create(['event_id' => $occurrence->event_id, 'city_id' => $this->city->getKey()]);
    Sponsorship::factory()->placement(SponsorshipPlacement::HomeCard)->create(['event_id' => $occurrence->event_id, 'city_id' => $this->city->getKey()]);

    foreach (['/api/v1/sponsorships?placement=list_top', '/api/v1/home'] as $indirizzo) {
        $risposta = $this->getJson($indirizzo)->assertOk();

        /* Una cache condivisa servirebbe lo stesso gettone a più persone, e la
           deduplica ne conterebbe una sola. */
        expect($risposta->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and($risposta->headers->get('Cache-Control'))->not->toContain('public')
            ->and($risposta->headers->get('ETag'))->toBeNull();
    }
});

it('conta una volta sola l app che manda insieme il gettone giornaliero e quello nuovo', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $campaign = Sponsorship::factory()->placement(SponsorshipPlacement::ListTop)->create(['event_id' => $occurrence->event_id, 'city_id' => $this->city->getKey()]);
    $data = $this->getJson('/api/v1/sponsorships?placement=list_top')->assertOk()->json('data.0');

    // Le intestazioni che manda l'app Android da questa versione.
    $headers = ['X-Metric-Token' => $data['metric_token'], 'X-Impression-Token' => $data['impression_token'], 'X-Installation-ID' => 'android-installation-0002'];
    $url = '/api/v1/reports/sponsorships/'.$campaign->getKey().'/metrics/impressions';
    $this->withHeaders($headers)->postJson($url)->assertNoContent();
    $this->withHeaders($headers)->postJson($url)->assertNoContent();

    expect((int) $campaign->refresh()->impressions)->toBe(1);
});

/*
 * L'avanzamento per le risposte di gestione: `goal`, `delivered`,
 * `pacing_state` dell'unità di consegna.
 */

it('dà l avanzamento di una campagna a periodo senza obiettivo né stato', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $campaign = Sponsorship::factory()->create(['event_id' => $occurrence->event_id, 'city_id' => $this->city->getKey()]);
    $campaign->forceFill(['impressions' => 321])->saveQuietly();

    expect(SponsorshipProgressResource::toArray($campaign->refresh()))
        ->toBe(['goal' => null, 'delivered' => 321, 'pacing_state' => null]);
});

it('dà lo stato del ritmo di una campagna a visualizzazioni', function (int $delivered, string $state): void {
    // Dal 9 settembre a mezzanotte all'ultima data, il 12 alle 22: a metà strada abbondante.
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $campaign = Sponsorship::factory()->goal(300)->create([
        'event_id' => $occurrence->event_id,
        'city_id' => $this->city->getKey(),
        'starts_at' => localInstant($this->city, '2026-09-09 00:00')->utc(),
        'ends_at' => localInstant($this->city, '2026-10-09 00:00')->utc(),
    ]);
    $campaign->forceFill(['impressions' => $delivered])->saveQuietly();

    expect(SponsorshipProgressResource::toArray($campaign->refresh()))
        ->toBe(['goal' => 300, 'delivered' => $delivered, 'pacing_state' => $state]);
})->with([
    'niente consegnato a metà periodo' => [0, 'behind'],
    'obiettivo raggiunto' => [300, 'completed'],
]);

it('dice in linea una campagna a visualizzazioni che non è ancora cominciata', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-25 19:00:00');
    $campaign = Sponsorship::factory()->goal(500)->create([
        'event_id' => $occurrence->event_id,
        'city_id' => $this->city->getKey(),
        'starts_at' => localInstant($this->city, '2026-09-20 00:00')->utc(),
        'ends_at' => localInstant($this->city, '2026-09-30 00:00')->utc(),
    ]);

    expect(SponsorshipProgressResource::toArray($campaign))->toBe(['goal' => 500, 'delivered' => 0, 'pacing_state' => 'on_track']);
});

/*
 * D70: app, pannelli e rapporti usano lo stesso calcolo (`SponsorshipProgress`).
 * Una campagna la cui ultima data è passata mentre il periodo continua è
 * «ferma», non chiusa: si giudica sul periodo, perché all'evento si possono
 * aggiungere date. Chiusa sotto l'obiettivo lo è a periodo finito.
 */
it('giudica sul periodo una campagna ferma la cui ultima data è passata, e la dice chiusa sotto l obiettivo a periodo finito', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-08 19:00:00');
    $campaign = Sponsorship::factory()->goal(500)->create([
        'event_id' => $occurrence->event_id,
        'city_id' => $this->city->getKey(),
        'starts_at' => localInstant($this->city, '2026-09-01 00:00')->utc(),
        'ends_at' => localInstant($this->city, '2026-09-30 00:00')->utc(),
    ]);
    $campaign->forceFill(['impressions' => 120])->saveQuietly();

    expect(SponsorshipProgressResource::toArray($campaign->refresh()))->toBe(['goal' => 500, 'delivered' => 120, 'pacing_state' => 'slightly_behind'])
        ->and(SponsorshipProgressResource::toArray($campaign, localInstant($this->city, '2026-10-01 09:00')))->toBe(['goal' => 500, 'delivered' => 120, 'pacing_state' => 'missed']);
});

it('dà a ogni riga di un abilitazione il numero dell abilitazione, cestino compreso', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
    $grant = SponsorshipGrant::factory()->goal(1000)->create([
        'venue_id' => $venue->getKey(),
        'starts_at' => localInstant($this->city, '2026-09-10 17:00')->utc(),
        'ends_at' => localInstant($this->city, '2026-10-10 00:00')->utc(),
    ]);
    $rows = collect(['2026-09-12 19:00:00', '2026-09-20 19:00:00', '2026-09-21 19:00:00'])
        ->map(fn (string $at): Sponsorship => Sponsorship::factory()->forGrant($grant)->create([
            'event_id' => occurrenceAt($this->city, $this->category, $at, venue: $venue)->event_id,
            'city_id' => $this->city->getKey(),
        ]));
    $rows[0]->forceFill(['impressions' => 60])->saveQuietly();
    $rows[1]->forceFill(['impressions' => 40])->saveQuietly();
    $rows[2]->forceFill(['impressions' => 10])->saveQuietly();
    $rows[2]->delete();

    $progress = SponsorshipProgressResource::many($rows->take(2)->map->refresh());

    expect($progress)->toBe([
        $rows[0]->id => ['goal' => 1000, 'delivered' => 110, 'pacing_state' => 'ahead'],
        $rows[1]->id => ['goal' => 1000, 'delivered' => 110, 'pacing_state' => 'ahead'],
    ]);
});
