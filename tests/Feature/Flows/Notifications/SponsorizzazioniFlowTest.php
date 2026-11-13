<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Models\Sponsorship;
use App\Settings\SponsorshipBannerSettings;
use Illuminate\Support\Str;

/**
 * Le campagne sponsorizzate viste da chi naviga: il banner chiesto dalla
 * pagina (web) o dall'app (Android), il clic che porta alla scheda, e le
 * misure che finiscono in fattura — contate una volta, mai gonfiabili, e
 * ferme quando la campagna si ferma.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 20:00');

    $this->data = occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00', event: ['title' => 'Concerto sponsorizzato in piazza']);
    $this->campagna = Sponsorship::factory()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $this->data->event_id,
        'placement' => SponsorshipPlacement::ListTop,
        'advertiser_name' => 'Birrificio di prova',
    ]);
});

it('mostra il banner sul sito, porta alla scheda, conta visualizzazione e clic e si ferma con la campagna', function (): void {
    // Senza piattaforma la richiesta è malformata.
    $this->getJson('/banner-sponsorizzato')->assertUnprocessable();

    // 1. La pagina chiede il banner: dati dell'evento, mai in cache condivisa.
    $banner = $this->getJson('/banner-sponsorizzato?platform=web')
        ->assertOk()
        ->assertJsonPath('data.id', $this->campagna->getKey())
        ->assertJsonPath('data.title', 'Concerto sponsorizzato in piazza')
        ->assertJsonPath('data.advertiser', 'Birrificio di prova')
        ->assertJsonPath('data.occurrence_id', (int) $this->data->getKey());

    expect($banner->headers->get('Cache-Control'))->toContain('no-store', 'private');

    // 2. Il banner entra nello schermo e viene cliccato: due misure, una per tipo.
    $this->post('/sponsorizzazioni/'.$this->campagna->getKey().'/impressions')->assertNoContent();
    $this->post('/sponsorizzazioni/'.$this->campagna->getKey().'/clicks')->assertNoContent();

    // 3. Il clic porta alla scheda vera della data.
    $this->get((string) $banner->json('data.url'))->assertOk()->assertSee('Concerto sponsorizzato in piazza');

    expect($this->campagna->fresh())
        ->impressions->toBe(1)
        ->clicks->toBe(1);

    // Una misura inventata non esiste.
    $this->post('/sponsorizzazioni/'.$this->campagna->getKey().'/fatturato')->assertNotFound();

    // 4. L'amministrazione mette in pausa la campagna: niente banner, niente misure.
    $this->campagna->forceFill(['status' => SponsorshipStatus::Paused])->save();

    $this->getJson('/banner-sponsorizzato?platform=web')->assertOk()->assertJsonPath('data', null);
    $this->post('/sponsorizzazioni/'.$this->campagna->getKey().'/impressions')->assertNotFound();

    expect($this->campagna->fresh()->impressions)->toBe(1);

    // 5. Riattivata torna; spento il canale web resta solo su Android.
    $this->campagna->forceFill(['status' => SponsorshipStatus::Active])->save();
    $this->getJson('/banner-sponsorizzato?platform=web')->assertJsonPath('data.id', $this->campagna->getKey());

    $impostazioni = app(SponsorshipBannerSettings::class);
    $impostazioni->web_enabled = false;
    $impostazioni->save();

    $this->getJson('/banner-sponsorizzato?platform=web')->assertJsonPath('data', null);
    $this->getJson('/api/v1/sponsorships/banner?platform=android')->assertJsonPath('data.id', $this->campagna->getKey());
});

it('dall app elenca le campagne, misura con il gettone del giorno e non si lascia gonfiare i numeri', function (): void {
    // La collocazione è obbligatoria.
    $this->getJson('/api/v1/sponsorships')->assertUnprocessable();

    // 1. L'app chiede le campagne della lista: c'è la data e il gettone per misurare.
    $elenco = $this->getJson('/api/v1/sponsorships?placement=list_top')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->campagna->getKey())
        ->assertJsonPath('data.0.placement', 'list_top')
        ->assertJsonPath('data.0.advertiser.name', 'Birrificio di prova')
        ->assertJsonPath('data.0.occurrence.occurrence_id', (int) $this->data->getKey());

    $gettone = (string) $elenco->json('data.0.metric_token');
    $indirizzo = (string) $elenco->json('data.0.metric_url');

    expect($indirizzo)->toContain('/api/v1/reports/sponsorships/'.$this->campagna->getKey().'/metrics/METRIC');

    // Un'altra collocazione non la propone.
    $this->getJson('/api/v1/sponsorships?placement=home_hero')->assertOk()->assertJsonCount(0, 'data');

    $intestazioni = ['X-Metric-Token' => $gettone, 'X-Installation-ID' => 'telefono-di-prova'];
    $misura = fn (string $metrica): string => str_replace('METRIC', $metrica, $indirizzo);

    // 2. Una visualizzazione, ripetuta: contata una volta sola.
    $this->postJson($misura('impressions'), [], $intestazioni)->assertNoContent();
    $this->postJson($misura('impressions'), [], $intestazioni)->assertNoContent();

    expect($this->campagna->fresh()->impressions)->toBe(1);

    // 3. Un clic reinviato dopo una rete caduta collassa; un secondo clic vero passa.
    $clic = (string) Str::uuid();

    $this->postJson($misura('clicks'), ['click_id' => $clic], $intestazioni)->assertNoContent();
    $this->postJson($misura('clicks'), ['click_id' => $clic], $intestazioni)->assertNoContent();

    expect($this->campagna->fresh()->clicks)->toBe(1);

    $this->postJson($misura('clicks'), ['click_id' => (string) Str::uuid()], $intestazioni)->assertNoContent();

    expect($this->campagna->fresh()->clicks)->toBe(2);

    // 4. Senza gettone, o con uno falso, non si scrive nulla.
    $this->postJson($misura('impressions'), [], ['X-Installation-ID' => 'altro-telefono'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');
    $this->postJson($misura('impressions'), [], ['X-Metric-Token' => str_repeat('0', 64), 'X-Installation-ID' => 'altro-telefono'])
        ->assertStatus(400);

    // Una misura inventata non esiste.
    $this->postJson($misura('fatturato'), [], $intestazioni)->assertNotFound();

    expect($this->campagna->fresh())
        ->impressions->toBe(1)
        ->clicks->toBe(2);

    // 5. Il banner dell'app porta lo stesso gettone del giorno.
    $this->getJson('/api/v1/sponsorships/banner?platform=android')
        ->assertOk()
        ->assertJsonPath('data.id', $this->campagna->getKey())
        ->assertJsonPath('data.metric_token', $gettone);

    // 6. Dopo la fine della serata la campagna sparisce dall'elenco e non misura più.
    freezeLocal($this->city, '2026-09-12 10:00');

    $this->getJson('/api/v1/sponsorships?placement=list_top')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson($misura('impressions'), [], ['X-Metric-Token' => $gettone, 'X-Installation-ID' => 'telefono-nuovo'])->assertStatus(400);

    expect($this->campagna->fresh()->impressions)->toBe(1);
});
