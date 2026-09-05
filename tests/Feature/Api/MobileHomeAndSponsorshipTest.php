<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
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
