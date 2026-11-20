<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\SponsorshipPlacement;
use App\Models\Sponsorship;
use App\Services\Sponsorship\Delivery\HashDraw;
use App\Services\Sponsorship\Delivery\SeededDraw;
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

/**
 * Una sera ancora da venire, contata da oggi.
 *
 * Le date erano scritte per esteso, e una campagna si vede solo se l'evento sotto regge ancora: il giorno in cui quelle date sono passate i test hanno cominciato a fallire tutti insieme, senza che nulla fosse cambiato nel codice. Contarle da adesso è ciò che la selezione verifica davvero — «l'evento è ancora davanti», non «è il venti settembre».
 */
function seraDaVenire(int $fraGiorni = 1): string
{
    return CarbonImmutable::now(test()->city->timezone)->addDays($fraGiorni)->format('Y-m-d 21:00:00');
}

it('mostra una campagna attiva dentro la propria finestra', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, seraDaVenire());

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
    $occorrenza = occurrenceAtLocal($this->city, $this->category, seraDaVenire());

    Sponsorship::factory()->future()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
    ]);

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))->toBeEmpty();
});

it('non mostra una campagna finita', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, seraDaVenire());

    Sponsorship::factory()->expired()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
    ]);

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))->toBeEmpty();
});

it('non mostra una bozza né una campagna sospesa', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, seraDaVenire());

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
    $occorrenza = occurrenceAtLocal($this->city, $this->category, seraDaVenire());

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
    $occorrenza = occurrenceAtLocal($altra, $this->category, seraDaVenire());

    Sponsorship::factory()->create([
        'city_id' => $altra->getKey(),
        'event_id' => $occorrenza->event_id,
    ]);

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))->toBeEmpty();
});

it('non mescola le collocazioni', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, seraDaVenire());

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
        $occorrenza = occurrenceAtLocal($this->city, $this->category, seraDaVenire($indice));

        Sponsorship::factory()->create([
            'city_id' => $this->city->getKey(),
            'event_id' => $occorrenza->event_id,
        ]);
    }

    expect($this->selettore->forPlacement($this->city, SponsorshipPlacement::ListTop))
        ->toHaveCount(SponsorshipPlacement::ListTop->limit());
});

it('mette davanti chi ha la priorità più alta', function (): void {
    $bassa = occurrenceAtLocal($this->city, $this->category, seraDaVenire());
    $alta = occurrenceAtLocal($this->city, $this->category, seraDaVenire(2));

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
 * sempre.
 *
 * Riscritto con il piano di consegna. Prima la rotazione avanzava di minuto in
 * minuto e il test confrontava quattro minuti; adesso ogni richiesta estrae
 * dal piano, e la stessa promessa commerciale si verifica con un seme: su
 * quaranta richieste compaiono entrambe.
 */
it('alterna le campagne di pari priorità', function (): void {
    foreach ([1, 2] as $giorno) {
        $occorrenza = occurrenceAtLocal($this->city, $this->category, seraDaVenire($giorno));

        Sponsorship::factory()->create([
            'city_id' => $this->city->getKey(),
            'event_id' => $occorrenza->event_id,
            'priority' => 10,
        ]);
    }

    $selettore = $this->selettore->withDraw(new SeededDraw(20261119));

    $viste = collect(range(1, 40))
        ->map(fn (): ?int => $selettore->first($this->city, SponsorshipPlacement::ListTop)?->getKey())
        ->countBy();

    expect($viste)->toHaveCount(2, 'con due campagne di pari priorità devono comparire entrambe')
        ->and($viste->min())->toBeGreaterThan(10);
});

/*
 * Riscritto: prima «la stessa campagna dentro lo stesso minuto», perché la
 * scelta finiva nella pagina in cache. Adesso card e cima dell'elenco sono
 * buchi riempiti a ogni visita, e la sola scelta che resta nello scheletro è
 * l'apertura della home: lei deve restare la stessa per tutti dentro la
 * casella lunga quanto la cache.
 */
it('mostra la stessa apertura a chi ricarica dentro la stessa casella della cache', function (): void {
    foreach ([1, 2, 3] as $giorno) {
        $occorrenza = occurrenceAtLocal($this->city, $this->category, seraDaVenire($giorno));

        Sponsorship::factory()->placement(SponsorshipPlacement::HomeHero)->create([
            'city_id' => $this->city->getKey(),
            'event_id' => $occorrenza->event_id,
            'priority' => 10,
        ]);
    }

    $minuti = HashDraw::slotMinutes();
    $inizio = CarbonImmutable::createFromTimestamp(HashDraw::slotIndex(CarbonImmutable::now('UTC'), $minuti) * $minuti * 60, 'UTC');

    $scelte = collect([0, 1, 7, $minuti - 1])
        ->map(fn (int $minuto): ?int => $this->selettore
            ->first($this->city, SponsorshipPlacement::HomeHero, $inizio->addMinutes($minuto)->addSeconds(30))
            ?->getKey())
        ->unique();

    expect($scelte)->toHaveCount(1);
});
