<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Models\Sponsorship;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Le misure di una campagna: visualizzazioni e aperture.
 *
 * Finiscono in fattura, e per questo il test che conta di più non è quello che
 * verifica che il contatore salga: è quello che verifica che non lo si possa
 * gonfiare.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();

    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    $this->campagna = Sponsorship::factory()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
        'placement' => SponsorshipPlacement::ListTop,
    ]);

    RateLimiter::clear('sponsorship-metric:127.0.0.1:'.$this->campagna->getKey().':impressions');
    RateLimiter::clear('sponsorship-metric:127.0.0.1:'.$this->campagna->getKey().':clicks');
});

it('conta una visualizzazione e un apertura', function (): void {
    $this->post(route('sponsorships.metric', ['sponsorship' => $this->campagna, 'metric' => 'impressions']))
        ->assertNoContent();

    $this->post(route('sponsorships.metric', ['sponsorship' => $this->campagna, 'metric' => 'clicks']))
        ->assertNoContent();

    expect($this->campagna->fresh())
        ->impressions->toBe(1)
        ->clicks->toBe(1);
});

it('non accetta una misura inventata', function (): void {
    $this->post(route('sponsorships.metric', ['sponsorship' => $this->campagna, 'metric' => 'fatturato']))
        ->assertNotFound();
});

/*
 * Senza tetto, una persona con un ciclo `for` porta una campagna a centomila
 * visualizzazioni in un minuto — e quelle cifre finiscono in fattura.
 */
it('smette di contare oltre il tetto orario', function (): void {
    $url = route('sponsorships.metric', ['sponsorship' => $this->campagna, 'metric' => 'impressions']);

    foreach (range(1, 45) as $tentativo) {
        $this->post($url)->assertNoContent();
    }

    /* Il tetto è 30: le richieste continuano a rispondere «va bene» — al
       browser non serve saperlo — ma il contatore si ferma. */
    expect($this->campagna->fresh()->impressions)->toBe(30);
});

it('conta separatamente due campagne diverse', function (): void {
    $altra = Sponsorship::factory()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $this->campagna->event_id,
    ]);

    RateLimiter::clear('sponsorship-metric:127.0.0.1:'.$altra->getKey().':impressions');

    $this->post(route('sponsorships.metric', ['sponsorship' => $this->campagna, 'metric' => 'impressions']))->assertNoContent();

    expect($this->campagna->fresh()->impressions)->toBe(1)
        ->and($altra->fresh()->impressions)->toBe(0);
});

it('calcola il rapporto solo quando c è qualcosa da rapportare', function (): void {
    /* Zero visualizzazioni non è «zero per cento»: è una domanda senza
       risposta, e scriverla come zero fa credere che la campagna vada male
       quando non è ancora partita. */
    expect($this->campagna->clickRate())->toBeNull();

    /*
     * `forceFill` e non `update`: i due contatori non sono fra i campi
     * assegnabili in massa, di proposito — si incrementano, non si scrivono, e
     * un contatore che si puo' impostare da un modulo e' un contatore che
     * prima o poi qualcuno imposta. `update()` li ignorava in silenzio, ed e'
     * cosi' che questo test ha scoperto la cosa.
     */
    $this->campagna->forceFill(['impressions' => 200, 'clicks' => 6])->save();

    expect($this->campagna->fresh()->clickRate())->toBe(0.03);
});

it('la pagina porta gli indirizzi per contare, e il collegamento resta un href normale', function (): void {
    freezeLocal($this->city, '2026-09-05 12:00:00');

    $html = $this->get('/eventi')->assertOk()->getContent();

    expect($html)
        ->toContain('data-sponsorship-impression')
        ->toContain('data-sponsorship-click')
        /* Il visitatore non dipende dallo script: senza JavaScript le misure
           si perdono, la navigazione no. */
        ->toMatch('/<a[^>]*href="[^"]*\/eventi\/[^"]*"[^>]*rel="sponsored"/');
});
