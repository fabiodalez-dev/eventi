<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Models\Sponsorship;

/**
 * Che una sponsorizzazione si dichiari sempre come tale.
 *
 * **Questi non sono test di aspetto.** La pubblicità dev'essere riconoscibile
 * come tale — Codice del Consumo, art. 22-23 — e un collegamento pagato va
 * marcato `rel="sponsored"` per le linee guida di Google. Sono due obblighi, e
 * la cosa che li rende fragili è che si rompono in silenzio: una card senza
 * etichetta funziona benissimo, sembra perfino più pulita, e nessuno se ne
 * accorge finché non arriva qualcuno a chiedere conto.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

function campagnaSu(string $quando, SponsorshipPlacement $dove, string $committente = 'Etichetta Rossa'): Sponsorship
{
    $occorrenza = occurrenceAtLocal(test()->city, test()->category, $quando, event: ['title' => 'Concerto pagato']);

    return Sponsorship::factory()->create([
        'city_id' => test()->city->getKey(),
        'event_id' => $occorrenza->event_id,
        'placement' => $dove,
        'advertiser_name' => $committente,
    ]);
}

it('scrive «sponsorizzato» e il nome di chi paga, in cima ai risultati', function (): void {
    freezeLocal($this->city, '2026-09-05 12:00:00');
    campagnaSu('2026-09-20 21:00:00', SponsorshipPlacement::ListTop);

    $this->get('/eventi')
        ->assertOk()
        ->assertSee(__('sponsorships.label'))
        ->assertSee('Etichetta Rossa')
        ->assertSee('Concerto pagato');
});

it('scrive «sponsorizzato» anche nella pagina iniziale', function (): void {
    freezeLocal($this->city, '2026-09-05 12:00:00');
    campagnaSu('2026-09-20 21:00:00', SponsorshipPlacement::HomeCard, 'Birrificio del Piave');

    $this->get('/')
        ->assertOk()
        ->assertSee(__('sponsorships.label'))
        ->assertSee('Birrificio del Piave');
});

it('marca il collegamento come sponsorizzato per i motori di ricerca', function (): void {
    freezeLocal($this->city, '2026-09-05 12:00:00');
    campagnaSu('2026-09-20 21:00:00', SponsorshipPlacement::ListTop);

    $html = $this->get('/eventi')->assertOk()->getContent();

    expect($html)->toMatch('/<a[^>]*rel="sponsored"/');
});

it('non scrive «sponsorizzato» su un evento che non lo è', function (): void {
    freezeLocal($this->city, '2026-09-05 12:00:00');
    occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    $this->get('/eventi')
        ->assertOk()
        ->assertDontSee(__('sponsorships.label'));
});

/*
 * L'etichetta deve sopravvivere alla scadenza: una campagna finita non lascia
 * l'evento in cima SENZA etichetta — lo toglie del tutto.
 */
it('toglie del tutto la card quando la campagna finisce', function (): void {
    freezeLocal($this->city, '2026-09-05 12:00:00');
    $campagna = campagnaSu('2026-09-20 21:00:00', SponsorshipPlacement::ListTop);

    $this->get('/eventi')->assertOk()->assertSee(__('sponsorships.label'));

    $campagna->update(['ends_at' => now('UTC')->subDay()]);

    $this->get('/eventi')->assertOk()->assertDontSee(__('sponsorships.label'));
});

it('dichiara la sponsorizzazione anche nell API, per le applicazioni', function (): void {
    freezeLocal($this->city, '2026-09-05 12:00:00');
    $campagna = campagnaSu('2026-09-20 21:00:00', SponsorshipPlacement::ListTop, 'Consorzio del Prosecco');

    $evento = $campagna->event;

    /*
     * Un'applicazione che riceve gli eventi senza sapere quali sono a
     * pagamento non puo' dichiararlo: l'obbligo di trasparenza non si ferma
     * al browser.
     */
    $this->getJson('/api/v1/events/'.$evento->slug)
        ->assertOk()
        ->assertJsonPath('data.sponsored.advertiser', 'Consorzio del Prosecco');
});

it('non dichiara sponsorizzato nell API un evento con campagna scaduta', function (): void {
    freezeLocal($this->city, '2026-09-05 12:00:00');
    $campagna = campagnaSu('2026-09-20 21:00:00', SponsorshipPlacement::ListTop);
    $campagna->update(['ends_at' => now('UTC')->subDay()]);

    $this->getJson('/api/v1/events/'.$campagna->event->slug)
        ->assertOk()
        ->assertJsonPath('data.sponsored', null);
});
