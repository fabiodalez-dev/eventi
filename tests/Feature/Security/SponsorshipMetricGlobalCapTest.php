<?php

declare(strict_types=1);

use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Il tetto globale per campagna e per ora sulle misure degli sponsor (D64).
 *
 * Il tetto per indirizzo ferma chi ha un solo indirizzo; con molti indirizzi
 * le cifre di una campagna — quelle che finiscono in fattura — salivano
 * comunque. Qui ogni richiesta arriva da un indirizzo diverso, quindi il
 * tetto per indirizzo non scatta mai: se le cifre si fermano, è il tetto
 * globale a fermarle.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
    config(['eventi.sponsorship_metric_hourly_cap' => ['impressions' => 5, 'clicks' => 3]]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function righeTettoSponsor(Sponsorship $campagna): int
{
    return Activity::query()->where('log_name', 'sicurezza')->where('event', 'sponsor_misure_oltre_tetto')
        ->where('properties->sponsorship_id', $campagna->getKey())->count();
}

it('scarta in silenzio le visualizzazioni oltre il tetto orario della campagna, da indirizzi diversi', function (): void {
    $campagna = Sponsorship::factory()->create(['city_id' => $this->city->id]);
    $url = route('sponsorships.metric', ['sponsorship' => $campagna, 'metric' => 'impressions']);

    foreach (range(1, 12) as $i) {
        // La risposta resta 204 anche oltre il tetto: nessun segnale a chi gonfia.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])->postJson($url)->assertNoContent();
    }

    expect((int) SponsorshipDailyStat::query()->where('sponsorship_id', $campagna->id)->sum('impressions'))->toBe(5)
        ->and((int) $campagna->refresh()->impressions)->toBe(5)
        // Una riga sola nel registro, per quante misure siano state scartate.
        ->and(righeTettoSponsor($campagna))->toBe(1);

    $riga = Activity::query()->where('event', 'sponsor_misure_oltre_tetto')->firstOrFail();
    expect($riga->properties['misura'])->toBe('impressions')->and($riga->properties['tetto'])->toBe(5);
});

it('riparte all’ora successiva e segnala di nuovo una volta', function (): void {
    $campagna = Sponsorship::factory()->create(['city_id' => $this->city->id]);
    $url = route('sponsorships.metric', ['sponsorship' => $campagna, 'metric' => 'impressions']);

    foreach (range(1, 7) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])->postJson($url)->assertNoContent();
    }

    $this->travel(1)->hours();

    foreach (range(20, 27) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])->postJson($url)->assertNoContent();
    }

    expect((int) $campagna->refresh()->impressions)->toBe(10)
        ->and(righeTettoSponsor($campagna))->toBe(2);
});

it('conta il tetto per campagna: una campagna piena non ferma le altre', function (): void {
    $piena = Sponsorship::factory()->create(['city_id' => $this->city->id]);
    $altra = Sponsorship::factory()->create(['city_id' => $this->city->id]);

    foreach (range(1, 8) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
            ->postJson(route('sponsorships.metric', ['sponsorship' => $piena, 'metric' => 'impressions']))->assertNoContent();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
        ->postJson(route('sponsorships.metric', ['sponsorship' => $altra, 'metric' => 'impressions']))->assertNoContent();

    expect((int) $piena->refresh()->impressions)->toBe(5)
        ->and((int) $altra->refresh()->impressions)->toBe(1)
        ->and(righeTettoSponsor($altra))->toBe(0);
});

it('ferma anche i clic dall API con molti indirizzi e molti click_id', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $campagna = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $occurrence->event_id]);
    $token = hash_hmac('sha256', $campagna->getKey().'|'.$campagna->placement->value.'|'.now('UTC')->format('Y-m-d'), (string) config('app.key'));
    $url = '/api/v1/reports/sponsorships/'.$campagna->getKey().'/metrics/clicks';

    foreach (range(1, 9) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:'.dechex($i).'::1'])
            ->withHeaders(['X-Metric-Token' => $token])
            ->postJson($url, ['click_id' => (string) Str::uuid()])->assertNoContent();
    }

    expect((int) $campagna->refresh()->clicks)->toBe(3)
        ->and(SponsorshipClick::query()->where('sponsorship_id', $campagna->id)->count())->toBe(3)
        ->and(righeTettoSponsor($campagna))->toBe(1);
});

it('non lascia consumare il tetto dei clic con lo stesso click_id ripetuto', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $campagna = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $occurrence->event_id]);
    $token = hash_hmac('sha256', $campagna->getKey().'|'.$campagna->placement->value.'|'.now('UTC')->format('Y-m-d'), (string) config('app.key'));
    $url = '/api/v1/reports/sponsorships/'.$campagna->getKey().'/metrics/clicks';
    $ripetuto = (string) Str::uuid();

    // Dieci indirizzi rimandano lo stesso clic: conta una volta e non tocca il tetto.
    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:'.dechex($i).'::1'])
            ->withHeaders(['X-Metric-Token' => $token])
            ->postJson($url, ['click_id' => $ripetuto])->assertNoContent();
    }
    // I clic veri che seguono passano ancora, fino al tetto.
    foreach (range(20, 21) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:'.dechex($i).'::1'])
            ->withHeaders(['X-Metric-Token' => $token])
            ->postJson($url, ['click_id' => (string) Str::uuid()])->assertNoContent();
    }

    expect((int) $campagna->refresh()->clicks)->toBe(3)
        ->and(righeTettoSponsor($campagna))->toBe(0);
});

it('con il tetto a zero non ferma niente', function (): void {
    config(['eventi.sponsorship_metric_hourly_cap.impressions' => 0]);
    $campagna = Sponsorship::factory()->create(['city_id' => $this->city->id]);
    $url = route('sponsorships.metric', ['sponsorship' => $campagna, 'metric' => 'impressions']);

    foreach (range(1, 8) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])->postJson($url)->assertNoContent();
    }

    expect((int) $campagna->refresh()->impressions)->toBe(8)->and(righeTettoSponsor($campagna))->toBe(0);
});
