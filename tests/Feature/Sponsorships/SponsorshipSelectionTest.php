<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\SponsorshipPlacement;
use App\Models\Sponsorship;
use App\Services\Sponsorship\SponsorshipSelector;
use Carbon\CarbonImmutable;

/**
 * Quali campagne compaiono, e quando.
 *
 * Le tre condizioni di `Sponsorship::scopeVisible` hanno un test ciascuna,
 * perché sono la sola cosa che separa «una campagna pagata» da «una campagna
 * che compare»: se una salta, compare qualcosa che non dovrebbe — e nessuno se
 * ne accorge, perché una pubblicità di troppo non fa rumore.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->selettore = app(SponsorshipSelector::class);
});

it('mostra una campagna attiva dentro la propria finestra', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    $campagna = Sponsorship::factory()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
        'placement' => SponsorshipPlacement::ListTop,
    ]);

    $scelte = $this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop);

    expect($scelte)->toHaveCount(1)
        ->and($scelte->first()->getKey())->toBe($campagna->getKey());
});

it('non mostra una campagna non ancora cominciata', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    Sponsorship::factory()->future()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
    ]);

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))->toBeEmpty();
});

it('non mostra una campagna finita', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    Sponsorship::factory()->expired()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
    ]);

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))->toBeEmpty();
});

it('non mostra una bozza né una campagna sospesa', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    Sponsorship::factory()->draft()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
    ]);

    Sponsorship::factory()->paused()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
    ]);

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))->toBeEmpty();
});

/*
 * La terza condizione, quella che si dimentica: una campagna viva su un evento
 * che la redazione ha ritirato. Chi ha pagato non compra il diritto di tenere
 * in vetrina una serata annullata.
 */
it('non mostra una campagna il cui evento non è più pubblicato', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    Sponsorship::factory()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
    ]);

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))->toHaveCount(1);

    $occorrenza->event->update(['status' => EventStatus::Cancelled]);

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))->toBeEmpty();
});

it('non mostra la campagna di un altra città', function (): void {
    $altra = testCity(['name' => 'Vicenza', 'slug' => 'vicenza']);
    $occorrenza = occurrenceAtLocal($altra, $this->category, '2026-09-20 21:00:00');

    Sponsorship::factory()->create([
        'city_id' => $altra->getKey(),
        'event_id' => $occorrenza->event_id,
    ]);

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))->toBeEmpty();
});

it('non mescola le collocazioni', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    Sponsorship::factory()->placement(SponsorshipPlacement::HomeHero)->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
    ]);

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))->toBeEmpty()
        ->and($this->selettore->forPlacement($this->city, SponsorshipPlacement::HomeHero))->toHaveCount(1);
});

/*
 * Il tetto: e' cio' che impedisce che vendere cambi l'aspetto del prodotto.
 */
it('non supera mai il tetto della collocazione, quante che siano le campagne', function (): void {
    foreach (range(1, 5) as $indice) {
        $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-2'.$indice.' 21:00:00');

        Sponsorship::factory()->create([
            'city_id' => $this->city->getKey(),
            'event_id' => $occorrenza->event_id,
        ]);
    }

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))
        ->toHaveCount(SponsorshipPlacement::ListTop->limit());
});

it('mette davanti chi ha la priorità più alta', function (): void {
    $bassa = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');
    $alta = occurrenceAtLocal($this->city, $this->category, '2026-09-21 21:00:00');

    Sponsorship::factory()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $bassa->event_id,
        'priority' => 1,
    ]);

    $vincente = Sponsorship::factory()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $alta->event_id,
        'priority' => 100,
    ]);

    expect($this->selettore->first($this->city, SponsorshipPlacement::ListTop)?->getKey())
        ->toBe($vincente->getKey());
});

/*
 * La rotazione: a parita' di priorita' nessuna campagna deve stare davanti per
 * sempre. E' deterministica dentro il minuto — le pagine stanno in cache — e
 * si verifica confrontando due minuti diversi.
 */
it('alterna le campagne di pari priorità di minuto in minuto', function (): void {
    foreach ([20, 21] as $giorno) {
        $occorrenza = occurrenceAtLocal($this->city, $this->category, "2026-09-{$giorno} 21:00:00");

        Sponsorship::factory()->create([
            'city_id' => $this->city->getKey(),
            'event_id' => $occorrenza->event_id,
            'priority' => 10,
        ]);
    }

    $viste = collect(range(0, 3))
        ->map(fn (int $minuto): ?int => $this->selettore
            ->first($this->city, SponsorshipPlacement::ListTop, CarbonImmutable::now('UTC')->addMinutes($minuto))
            ?->getKey())
        ->unique();

    expect($viste)->toHaveCount(2, 'con due campagne di pari priorità devono comparire entrambe');
});

it('mostra la stessa campagna a chi ricarica dentro lo stesso minuto', function (): void {
    foreach ([20, 21] as $giorno) {
        $occorrenza = occurrenceAtLocal($this->city, $this->category, "2026-09-{$giorno} 21:00:00");

        Sponsorship::factory()->create([
            'city_id' => $this->city->getKey(),
            'event_id' => $occorrenza->event_id,
            'priority' => 10,
        ]);
    }

    /*
     * Se la rotazione fosse casuale la pagina in cache mostrerebbe una
     * campagna e il server ne sceglierebbe un'altra al giro dopo: chi ricarica
     * vedrebbe cambiare le cose senza motivo.
     *
     * `startOfMinute()` non e' pignoleria: la rotazione avanza al CAMBIO di
     * minuto, non sessanta secondi dopo l'ultima richiesta. Partendo da un
     * istante qualunque, i trenta secondi qui sotto cadrebbero nel minuto
     * seguente circa una volta su due, e il test sarebbe rosso a intermittenza
     * senza che niente sia rotto.
     */
    $istante = CarbonImmutable::now('UTC')->startOfMinute();

    $prima = $this->selettore->first($this->city, SponsorshipPlacement::ListTop, $istante);
    $seconda = $this->selettore->first($this->city, SponsorshipPlacement::ListTop, $istante->addSeconds(30));

    expect($prima?->getKey())->toBe($seconda?->getKey());
});
