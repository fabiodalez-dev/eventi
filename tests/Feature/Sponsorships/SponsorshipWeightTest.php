<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Models\Sponsorship;
use App\Services\Sponsorship\SponsorshipSelector;
use Carbon\CarbonImmutable;

/*
 * La rotazione a peso.
 *
 * Prima c'era solo `priority`, che e' un ordine: chi ce l'ha piu' alta sta
 * davanti. Nella collocazione in apertura, che ammette UNA sola campagna,
 * questo significa che la priorita' piu' alta prende il cento per cento delle
 * apparizioni e le altre non compaiono mai — anche se hanno pagato. Fra due
 * clienti si poteva vendere solo «il primo posto», e una volta venduto non
 * c'era piu' niente da vendere.
 */

/*
 * **Il tempo qui e' fermo, ed e' l'unico modo perche' questi test dicano
 * sempre la stessa cosa.**
 *
 * Le campagne nascono con una finestra ancorata a `now()` (da ieri a domani),
 * mentre i cicli qui sotto scorrono i minuti a partire da una data scritta a
 * mano. Finche' le due coincidono va tutto bene; il giorno dopo la finestra
 * scivola avanti, il ciclo resta indietro, e i conteggi crollano — «31 invece
 * di 200» — come se la rotazione fosse rotta.
 *
 * E' successo davvero: scritti il 2 settembre, rossi il 3, per il solo
 * passare della mezzanotte. Un test che dipende dal giorno in cui gira non
 * misura il codice, misura il calendario, e quando fallisce manda a cercare
 * un guasto dove non c'e'.
 */
beforeEach(function (): void {
    test()->travelTo(CarbonImmutable::parse('2026-09-02 00:00'));

    $this->citta = testCity();
    $this->categoria = testCategory();
});

/**
 * Una campagna su un evento vero e pubblicato.
 *
 * Serve l'occorrenza e non solo l'evento: `scopeVisible` pretende che l'evento
 * sotto regga ancora — una campagna pagata non tiene in vetrina un evento
 * annullato — e senza una data pubblicata quel controllo non passa.
 */
function campagnaConPeso(int $peso, string $nome, int $priorita = 5): Sponsorship
{
    $citta = test()->citta;
    $occorrenza = occurrenceAtLocal($citta, test()->categoria, '2026-09-20 21:00:00');

    return Sponsorship::factory()->create([
        'city_id' => $citta->getKey(),
        'event_id' => $occorrenza->event_id,
        'placement' => SponsorshipPlacement::HomeHero,
        'status' => SponsorshipStatus::Active,
        'starts_at' => CarbonImmutable::now()->subDay(),
        'ends_at' => CarbonImmutable::now()->addDay(),
        'priority' => $priorita,
        'weight' => $peso,
        'advertiser_name' => $nome,
    ]);
}

it('divide le apparizioni in proporzione al peso', function (): void {
    /*
     * Il cuore della faccenda: peso 3 contro peso 1 non vuol dire «sta
     * davanti», vuol dire «compare tre volte su quattro». E' cio' che permette
     * di vendere una quota invece di una posizione.
     */
    $citta = $this->citta;

    campagnaConPeso(3, 'pesa tre');
    campagnaConPeso(1, 'pesa uno');

    $selettore = app(SponsorshipSelector::class);
    $base = CarbonImmutable::parse('2026-09-02 00:00');
    $conteggio = [];

    /* Quattrocento minuti: abbastanza perche' la proporzione si veda, e un
       multiplo del periodo (4) perche' non ci sia un resto a falsarla. */
    for ($minuto = 0; $minuto < 400; $minuto++) {
        $prima = $selettore->forPlacement($citta, SponsorshipPlacement::HomeHero, $base->addMinutes($minuto))->first();

        if ($prima !== null) {
            $conteggio[$prima->advertiser_name] = ($conteggio[$prima->advertiser_name] ?? 0) + 1;
        }
    }

    expect($conteggio['pesa tre'] ?? 0)->toBe(300)
        ->and($conteggio['pesa uno'] ?? 0)->toBe(100);
});

it('non lascia a zero nessuno, che e il motivo per cui il peso esiste', function (): void {
    /*
     * Con la sola priorita', chi comprava meno non compariva MAI in una
     * collocazione da uno solo. Questo test e' la garanzia che non torni a
     * succedere.
     */
    $citta = $this->citta;

    campagnaConPeso(10, 'il grosso');
    campagnaConPeso(1, 'il piccolo');

    $selettore = app(SponsorshipSelector::class);
    $base = CarbonImmutable::parse('2026-09-02 00:00');
    $visto = [];

    for ($minuto = 0; $minuto < 60; $minuto++) {
        $prima = $selettore->forPlacement($citta, SponsorshipPlacement::HomeHero, $base->addMinutes($minuto))->first();

        if ($prima !== null) {
            $visto[$prima->advertiser_name] = true;
        }
    }

    expect($visto)->toHaveKeys(['il grosso', 'il piccolo']);
});

it('la priorita resta piu forte del peso', function (): void {
    /* La priorita' e' un impegno contrattuale: «sei sempre in cima». Il peso
       distribuisce dentro quel gruppo, non lo scavalca. */
    $citta = $this->citta;

    campagnaConPeso(1, 'priorita alta', priorita: 10);
    campagnaConPeso(50, 'priorita bassa ma pesantissima', priorita: 1);

    $selettore = app(SponsorshipSelector::class);
    $base = CarbonImmutable::parse('2026-09-02 00:00');

    for ($minuto = 0; $minuto < 40; $minuto++) {
        $prima = $selettore->forPlacement($citta, SponsorshipPlacement::HomeHero, $base->addMinutes($minuto))->first();

        expect($prima?->advertiser_name)->toBe('priorita alta');
    }
});

it('resta uguale a se stessa dentro lo stesso minuto', function (): void {
    /*
     * Senza questo la rotazione a peso romperebbe la cache di pagina: la
     * pagina salvata conterrebbe una scelta valida per tutto il minuto, cioe'
     * in pratica sempre la stessa presa a caso.
     */
    $citta = $this->citta;

    campagnaConPeso(3, 'una');
    campagnaConPeso(2, 'altra');

    $selettore = app(SponsorshipSelector::class);
    $istante = CarbonImmutable::parse('2026-09-02 14:37:05');

    $prima = $selettore->forPlacement($citta, SponsorshipPlacement::HomeHero, $istante)->first();
    $poi = $selettore->forPlacement($citta, SponsorshipPlacement::HomeHero, $istante->addSeconds(50))->first();

    expect($prima?->getKey())->toBe($poi?->getKey());
});

it('un peso a zero vale uno, non «mai»', function (): void {
    /*
     * Chi scrive zero in un campo chiamato «peso» non si aspetta di aver
     * spento la campagna: per spegnerla c'e' lo stato, ed e' li' che va
     * cercata quando non compare.
     */
    $citta = $this->citta;

    campagnaConPeso(0, 'peso zero');

    $trovate = app(SponsorshipSelector::class)->forPlacement(
        $citta,
        SponsorshipPlacement::HomeHero,
        CarbonImmutable::now(),
    );

    expect($trovate)->toHaveCount(1);
});

it('smette di mostrare una campagna che ha esaurito il tetto di visualizzazioni', function (): void {
    /*
     * E' il modo in cui si vende pubblicita' quasi ovunque: non a tempo ma a
     * numero di visualizzazioni. Il confronto sta nella query e non in PHP,
     * cosi' una campagna esaurita non viene nemmeno caricata con le sue
     * relazioni per essere poi scartata.
     */
    $citta = $this->citta;

    $esaurita = campagnaConPeso(1, 'esaurita');
    /* `forceFill` e non `update`: `impressions` non e' assegnabile di
       proposito — quel contatore si incrementa, non si scrive — e un `update`
       lo ignorerebbe in silenzio lasciando la campagna a zero, cioe' sotto il
       tetto che questo test vuole vederle superare. */
    $esaurita->forceFill(['impressions' => 1000, 'impressions_cap' => 1000])->save();

    $viva = campagnaConPeso(1, 'ancora viva');
    $viva->forceFill(['impressions' => 10, 'impressions_cap' => 1000])->save();

    $trovate = app(SponsorshipSelector::class)->forPlacement(
        $citta,
        SponsorshipPlacement::HomeHero,
        CarbonImmutable::now(),
    );

    expect($trovate->pluck('advertiser_name')->all())->toBe(['ancora viva']);
});

it('senza tetto dichiarato una campagna non si esaurisce mai', function (): void {
    /* `null` significa «nessun tetto», che e' il comportamento di sempre e
       resta quello predefinito. */
    $citta = $this->citta;

    $campagna = campagnaConPeso(1, 'senza tetto');
    $campagna->forceFill(['impressions' => 999_999, 'impressions_cap' => null])->save();

    expect(app(SponsorshipSelector::class)->forPlacement($citta, SponsorshipPlacement::HomeHero, CarbonImmutable::now()))
        ->toHaveCount(1);
});
