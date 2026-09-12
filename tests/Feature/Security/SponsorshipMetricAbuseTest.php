<?php

declare(strict_types=1);

use App\Enums\SponsorshipStatus;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;

/**
 * Le misure di una campagna finiscono in fattura: chi le scrive va contato.
 *
 * Il difetto stava nell'asimmetria fra le due porte. Il controller web aveva
 * un tetto per chiamante e campagna; quello dell'API no, e i suoi tre freni
 * erano tutti governati da chi attacca: il `metric_token` si ottiene
 * chiedendolo a `GET /api/v1/sponsorships`, la deduplica si disattiva
 * mandando `clicks` con un `click_id` nuovo, e il limite globale per gli
 * anonimi si conta su `X-Installation-ID`, che è il client a scegliere.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->campagna = Sponsorship::factory()->create(['city_id' => $this->city->id]);
});

it('non conta due volte la stessa campagna oltre il tetto orario, sul sito', function (): void {
    $url = route('sponsorships.metric', ['sponsorship' => $this->campagna, 'metric' => 'impressions']);

    for ($i = 0; $i < 35; $i++) {
        $this->postJson($url)->assertNoContent();
    }

    /* Trenta passano, le altre cinque vengono assorbite in silenzio: al
       browser non interessa, e la cifra non deve salire. */
    expect((int) SponsorshipDailyStat::query()->where('sponsorship_id', $this->campagna->getKey())->sum('impressions'))
        ->toBe(30);
});

it('non scrive misure su una campagna in bozza o in pausa', function (): void {
    foreach ([SponsorshipStatus::Draft, SponsorshipStatus::Paused] as $stato) {
        $spenta = Sponsorship::factory()->create(['city_id' => $this->city->id, 'status' => $stato]);

        $this->postJson(route('sponsorships.metric', ['sponsorship' => $spenta, 'metric' => 'impressions']))
            ->assertNotFound();

        expect(SponsorshipDailyStat::query()->where('sponsorship_id', $spenta->getKey())->exists())->toBeFalse();
    }
});

/**
 * L'interpolazione chiusa alla fonte.
 *
 * `SponsorshipDailyStat::registra()` mette `$colonna` dentro un `DB::raw()`:
 * è l'unico punto del progetto in cui una variabile entra nel testo di una
 * query. Non era sfruttabile perché entrambi i chiamanti la vincolavano
 * prima — ma quella sicurezza stava in chi chiama, e un terzo chiamante
 * scritto in fretta l'avrebbe aperta.
 */
it('rifiuta una colonna che non sia una delle due misure', function (): void {
    expect(fn () => SponsorshipDailyStat::registra($this->campagna->getKey(), 'impressions`, `clicks'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => SponsorshipDailyStat::registra($this->campagna->getKey(), 'qualunque'))
        ->toThrow(InvalidArgumentException::class);

    /* E le due ammesse continuano a funzionare: una difesa che blocca anche
       l'uso legittimo viene togliata al primo inciampo. */
    SponsorshipDailyStat::registra($this->campagna->getKey(), 'impressions');
    SponsorshipDailyStat::registra($this->campagna->getKey(), 'clicks');

    $riga = SponsorshipDailyStat::query()->where('sponsorship_id', $this->campagna->getKey())->firstOrFail();

    expect((int) $riga->impressions)->toBe(1)->and((int) $riga->clicks)->toBe(1);
});
