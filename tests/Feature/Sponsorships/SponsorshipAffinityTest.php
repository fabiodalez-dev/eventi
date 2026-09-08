<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Models\Category;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Sponsorship\SponsorshipSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/*
 * Le campagne tengono conto di cosa l'utente ha salvato.
 *
 * Chi salva concerti vede piu' spesso campagne su concerti. Il segnale e'
 * **solo** la wishlist — un gesto esplicito, che l'utente vede nella propria
 * lista e puo' togliere — non la cronologia di navigazione ne' cookie di
 * tracciamento.
 *
 * Vale **solo per chi e' autenticato**: le pagine degli anonimi passano dalla
 * cache condivisa (`CachePage` esclude gli autenticati), e una scelta
 * personalizzata finita li' verrebbe servita a tutti.
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

    Cache::flush();

    $this->citta = testCity();
    $this->concerti = Category::factory()->create(['name' => 'Musica dal vivo', 'slug' => 'musica-prova']);
    $this->mostre = Category::factory()->create(['name' => 'Arte e mostre', 'slug' => 'arte-prova']);
});

/** Una campagna su un evento di una certa categoria. */
function campagnaSuCategoria(Category $categoria, string $nome, int $peso = 1): Sponsorship
{
    $occorrenza = occurrenceAtLocal(test()->citta, $categoria, '2026-09-20 21:00:00');

    return Sponsorship::factory()->create([
        'city_id' => test()->citta->getKey(),
        'event_id' => $occorrenza->event_id,
        'placement' => SponsorshipPlacement::HomeHero,
        'status' => SponsorshipStatus::Active,
        'starts_at' => CarbonImmutable::now()->subDay(),
        'ends_at' => CarbonImmutable::now()->addDay(),
        'priority' => 5,
        'weight' => $peso,
        'advertiser_name' => $nome,
    ]);
}

/** Un utente che ha salvato una data di quella categoria. */
function utenteCheSalva(Category $categoria): User
{
    $utente = User::factory()->create();
    $occorrenza = occurrenceAtLocal(test()->citta, $categoria, '2026-10-05 21:00:00');

    $utente->savedOccurrences()->attach($occorrenza->getKey());

    return $utente;
}

/** Quante volte ciascuna campagna compare per prima, su N minuti. */
function conteggioSuMinuti(int $minuti = 400): array
{
    $selettore = app(SponsorshipSelector::class);
    $base = CarbonImmutable::parse('2026-09-02 00:00');
    $conteggio = [];

    for ($m = 0; $m < $minuti; $m++) {
        $prima = $selettore->forPlacement(test()->citta, SponsorshipPlacement::HomeHero, $base->addMinutes($m))->first();

        if ($prima !== null) {
            $conteggio[$prima->advertiser_name] = ($conteggio[$prima->advertiser_name] ?? 0) + 1;
        }
    }

    return $conteggio;
}

it('mostra piu spesso la categoria che l utente salva', function (): void {
    campagnaSuCategoria($this->concerti, 'un concerto');
    campagnaSuCategoria($this->mostre, 'una mostra');

    /* Senza nessuno collegato: pari peso, pari apparizioni. */
    $anonimo = conteggioSuMinuti();

    expect($anonimo['un concerto'])->toBe(200)
        ->and($anonimo['una mostra'])->toBe(200);

    /* Con un utente che salva concerti: il concerto sale. */
    $this->actingAs(utenteCheSalva($this->concerti));

    $affine = conteggioSuMinuti();

    expect($affine['un concerto'])->toBeGreaterThan($anonimo['un concerto'])
        ->and($affine['una mostra'])->toBeLessThan($anonimo['una mostra']);
});

it('inclina la bilancia senza ribaltarla: chi ha comprato compare comunque', function (): void {
    /*
     * Il difetto della prima versione, che metteva le affini davanti a tutte:
     * con una sola campagna affine, quell'utente vedeva quella il cento per
     * cento delle volte e le altre MAI — cioe' il problema che il peso era
     * nato per risolvere, reintrodotto per utente.
     *
     * Chi paga ha comprato una quota e deve ritrovarla anche fra gli utenti a
     * cui la sua categoria interessa meno.
     */
    campagnaSuCategoria($this->concerti, 'un concerto');
    campagnaSuCategoria($this->mostre, 'una mostra');

    $this->actingAs(utenteCheSalva($this->concerti));

    $conteggio = conteggioSuMinuti();

    expect($conteggio['una mostra'] ?? 0)->toBeGreaterThan(0, 'chi non e affine non deve sparire');
});

it('usa il peso inferito 1.5 senza superare le preferenze esplicite', function (): void {

    campagnaSuCategoria($this->concerti, 'un concerto');
    campagnaSuCategoria($this->mostre, 'una mostra');

    $this->actingAs(utenteCheSalva($this->concerti));

    $conteggio = conteggioSuMinuti(300);

    expect($conteggio['un concerto'])->toBe(180)
        ->and($conteggio['una mostra'])->toBe(120);
});

it('non cambia niente per chi non e collegato', function (): void {
    /* Le pagine degli anonimi stanno in cache condivisa: una scelta
       personalizzata finita li' verrebbe servita a tutti. */
    campagnaSuCategoria($this->concerti, 'un concerto');
    campagnaSuCategoria($this->mostre, 'una mostra');

    $conteggio = conteggioSuMinuti();

    expect($conteggio['un concerto'])->toBe(200)
        ->and($conteggio['una mostra'])->toBe(200);
});

it('non cambia niente per un utente senza salvataggi', function (): void {
    campagnaSuCategoria($this->concerti, 'un concerto');
    campagnaSuCategoria($this->mostre, 'una mostra');

    $this->actingAs(User::factory()->create());

    $conteggio = conteggioSuMinuti();

    expect($conteggio['un concerto'])->toBe(200)
        ->and($conteggio['una mostra'])->toBe(200);
});

it('la priorita resta piu forte dell affinita', function (): void {
    /* La priorita' e' un impegno contrattuale: nessuna preferenza personale
       la scavalca. */
    $mostra = campagnaSuCategoria($this->mostre, 'mostra in cima');
    $mostra->update(['priority' => 10]);

    campagnaSuCategoria($this->concerti, 'concerto affine ma sotto');

    $this->actingAs(utenteCheSalva($this->concerti));

    $conteggio = conteggioSuMinuti(50);

    expect($conteggio)->toHaveKey('mostra in cima')
        ->and($conteggio['mostra in cima'])->toBe(50)
        ->and($conteggio)->not->toHaveKey('concerto affine ma sotto');
});

it('non guarda oltre la finestra dei salvataggi recenti', function (): void {
    /*
     * La finestra guarda a QUANDO si e' salvato, non a quando si svolge
     * l'evento: e' il gesto a dire cosa interessa adesso. Un gusto espresso
     * due anni fa non e' un gusto di oggi.
     */
    campagnaSuCategoria($this->concerti, 'un concerto');
    campagnaSuCategoria($this->mostre, 'una mostra');

    $utente = User::factory()->create();
    $vecchia = occurrenceAtLocal($this->citta, $this->concerti, '2026-10-05 21:00:00');

    $utente->savedOccurrences()->attach($vecchia->getKey(), [
        'created_at' => CarbonImmutable::now()->subDays(config()->integer('eventi.sponsorship_affinity_window_days') + 30),
        'updated_at' => CarbonImmutable::now()->subDays(config()->integer('eventi.sponsorship_affinity_window_days') + 30),
    ]);

    $this->actingAs($utente);

    $conteggio = conteggioSuMinuti();

    expect($conteggio['un concerto'])->toBe(200)
        ->and($conteggio['una mostra'])->toBe(200);
});

it('usa solo la wishlist, non la navigazione', function (): void {
    /*
     * Non e' un dettaglio implementativo: e' la promessa che il sito fa a chi
     * lo usa. Il salvataggio e' un gesto esplicito e reversibile — si vede
     * nella propria lista e si puo' togliere — mentre una cronologia
     * racconterebbe cose che l'utente non ha mai deciso di dire.
     */
    $sorgente = (string) file_get_contents(app_path('Services/Sponsorship/CategoriePreferite.php'));

    expect($sorgente)->toContain('saved_events')
        ->and($sorgente)->not->toContain('Cookie')
        ->and($sorgente)->not->toContain('session');
});
