<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipSlotHourly;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * Il gettone usa e getta delle misure, visto da chi prova a gonfiarle.
 *
 * Prima la rotta web delle misure accettava qualunque campagna attiva senza
 * chiedere niente: un ciclo `for` scriveva in fattura. Ora ogni misura porta un
 * gettone firmato dal server per una sola riga, emesso da meno di sei ore, che
 * conta una volta sola. E l'API continua ad accettare il gettone giornaliero
 * delle app già installate: chi non aggiorna non smette di contare.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $this->campaign = Sponsorship::factory()->placement(SponsorshipPlacement::ListTop)->create([
        'event_id' => $occurrence->event_id,
        'city_id' => $this->city->getKey(),
    ]);
    $this->apiUrl = fn (string $metric): string => '/api/v1/reports/sponsorships/'.$this->campaign->getKey().'/metrics/'.$metric;
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function vecchioGettoneApi(Sponsorship $campaign): string
{
    return hash_hmac('sha256', $campaign->getKey().'|'.$campaign->placement->value.'|'.now('UTC')->format('Y-m-d'), (string) config('app.key'));
}

it('un gettone di un altra campagna non si riusa cambiando l indirizzo', function (): void {
    $altra = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $this->campaign->event_id]);
    $gettone = ImpressionToken::issue($altra, 'list_top');

    /* Stessa firma, numero di riga cambiato: la firma non torna più. */
    $parti = explode('.', $gettone);
    $parti[1] = (string) $this->campaign->getKey();
    $falso = implode('.', $parti);

    $this->postJson(route('sponsorships.metric', ['sponsorship' => $this->campaign, 'metric' => 'impressions']), [], ['X-Impression-Token' => $gettone])->assertNoContent();
    $this->postJson(route('sponsorships.metric', ['sponsorship' => $this->campaign, 'metric' => 'impressions']), [], ['X-Impression-Token' => $falso])->assertNoContent();

    expect($this->campaign->fresh()->impressions)->toBe(0)->and($altra->fresh()->impressions)->toBe(0);
});

it('un gettone firmato con un altra chiave non vale', function (): void {
    $altroServer = new ImpressionToken('base64:'.base64_encode(random_bytes(32)));
    $gettone = $altroServer->sign((int) $this->campaign->getKey(), 'list_top', CarbonImmutable::now('UTC'));

    $this->postJson(route('sponsorships.metric', ['sponsorship' => $this->campaign, 'metric' => 'impressions']), [], ['X-Impression-Token' => $gettone])->assertNoContent();

    expect($this->campaign->fresh()->impressions)->toBe(0);
});

it('il gettone si accetta anche nel corpo della richiesta, per chi non può mandare intestazioni', function (): void {
    $this->postJson(route('sponsorships.metric', ['sponsorship' => $this->campaign, 'metric' => 'impressions']), ['impression_token' => ImpressionToken::issue($this->campaign, 'list_top')])
        ->assertNoContent();

    expect($this->campaign->fresh()->impressions)->toBe(1);
});

it('l API conta con il gettone usa e getta, una volta sola, e sempre con 204', function (): void {
    $gettone = ImpressionToken::issue($this->campaign, 'banner');
    $headers = ['X-Impression-Token' => $gettone, 'X-Installation-ID' => 'android-installation-0001'];

    $this->withHeaders($headers)->postJson(($this->apiUrl)('impressions'))->assertNoContent();
    $this->withHeaders($headers)->postJson(($this->apiUrl)('impressions'))->assertNoContent();
    $this->withHeaders($headers)->postJson(($this->apiUrl)('clicks'), ['click_id' => (string) Str::uuid()])->assertNoContent();
    $this->withHeaders($headers)->postJson(($this->apiUrl)('clicks'), ['click_id' => (string) Str::uuid()])->assertNoContent();

    expect($this->campaign->fresh())->impressions->toBe(1)->clicks->toBe(1);

    $ora = SponsorshipSlotHourly::query()->sole();
    expect($ora->channel)->toBe('android')
        ->and($ora->placement)->toBe('banner')
        ->and($ora->sponsored_views)->toBe(1)
        ->and($ora->clicks)->toBe(1)
        ->and(SponsorshipClick::query()->sole()->channel)->toBe('android');
});

it('l API risponde 204 senza contare a un gettone nuovo falso, scaduto o di un altra città', function (): void {
    $this->withHeaders(['X-Impression-Token' => 'v1.1.banner.1.0000000000000000.falso'])->postJson(($this->apiUrl)('impressions'))->assertNoContent();

    $scaduto = ImpressionToken::issue($this->campaign, 'banner');
    $this->travel(7)->hours();
    $this->withHeaders(['X-Impression-Token' => $scaduto])->postJson(($this->apiUrl)('impressions'))->assertNoContent();

    /* La città dell'API è quella della richiesta: una campagna di Padova non
       si conta chiedendo un'altra città. */
    $altra = City::factory()->create(['is_active' => true, 'slug' => 'altrove']);
    $this->withHeaders(['X-Impression-Token' => ImpressionToken::issue($this->campaign, 'banner')])
        ->postJson(($this->apiUrl)('impressions').'?city='.$altra->slug)->assertNoContent();

    expect($this->campaign->fresh()->impressions)->toBe(0)
        ->and(SponsorshipDailyStat::query()->count())->toBe(0);
});

it('un app con il gettone giornaliero di prima continua a contare, con la deduplica di 15 minuti', function (): void {
    $headers = ['X-Metric-Token' => vecchioGettoneApi($this->campaign), 'X-Installation-ID' => 'android-installation-0002'];

    $this->withHeaders($headers)->postJson(($this->apiUrl)('impressions'))->assertNoContent();
    $this->withHeaders($headers)->postJson(($this->apiUrl)('impressions'))->assertNoContent();

    expect($this->campaign->fresh()->impressions)->toBe(1)
        // E il traffico dello spazio si misura anche da lì.
        ->and(SponsorshipSlotHourly::query()->sole()->sponsored_views)->toBe(1)
        ->and(SponsorshipSlotHourly::query()->sole()->channel)->toBe('android');

    $this->travel(16)->minutes();
    $this->withHeaders($headers)->postJson(($this->apiUrl)('impressions'))->assertNoContent();
    expect($this->campaign->fresh()->impressions)->toBe(2);

    // Il gettone di prima sbagliato resta un errore, come prima: l'API cambia solo per aggiunte.
    $this->withHeaders([...$headers, 'X-Metric-Token' => 'non-valido'])->postJson(($this->apiUrl)('impressions'))
        ->assertBadRequest()->assertJsonPath('error.code', 'INVALID_TOKEN');
});

it('l API con il gettone di prima non conta i programmi che si dichiarano', function (): void {
    $this->withHeaders(['X-Metric-Token' => vecchioGettoneApi($this->campaign), 'User-Agent' => 'python-requests/2.31'])
        ->postJson(($this->apiUrl)('impressions'))->assertNoContent();

    expect($this->campaign->fresh()->impressions)->toBe(0);
});

it('il tetto per indirizzo vale anche per il gettone nuovo dell API', function (): void {
    foreach (range(1, 35) as $ignored) {
        $this->withHeaders(['X-Impression-Token' => ImpressionToken::issue($this->campaign, 'banner')])
            ->postJson(($this->apiUrl)('impressions'))->assertNoContent();
    }

    expect($this->campaign->fresh()->impressions)->toBe(30);
});
