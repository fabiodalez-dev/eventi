<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\City;
use App\Models\Tag;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * La ricerca del sito (`/cerca`), con Scout su driver database.
 *
 * Due cose contano più delle altre: che i risultati siano **raggruppati**, e
 * che quali date siano ancora future continui a deciderlo il motore temporale
 * e non il motore di ricerca (§8.1).
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

it('raggruppa i risultati per tipo', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $locale = Venue::factory()->approved()->create([
        'city_id' => $city->getKey(),
        'name' => 'Circolo Marimba',
    ]);

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Serata marimba'], venue: $locale);

    Tag::factory()->create(['name' => 'marimba', 'slug' => 'marimba', 'is_approved' => true]);

    $risposta = $this->get('/cerca?q=marimba')->assertOk();

    $risposta->assertSee(__('search.groups.events'))
        ->assertSee(__('search.groups.venues'))
        ->assertSee(__('search.groups.tags'))
        ->assertSee('Serata marimba')
        ->assertSee('Circolo Marimba');
});

it('interroga davvero l\'indice full-text sulla descrizione', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: [
        'title' => 'Serata senza indizi',
        'description' => 'Si suona con un contrabbasso preso a prestito dal conservatorio.',
    ]);

    $eseguite = [];
    DB::listen(function (QueryExecuted $query) use (&$eseguite): void {
        $eseguite[] = strtolower($query->sql);
    });

    $this->get('/cerca?q=contrabbasso')->assertOk();

    /*
     * Che la riga venga trovata **non** si può verificare qui: InnoDB aggiorna
     * l'indice full-text al momento della commit, e ogni test vive dentro una
     * transazione che non viene mai chiusa. Si verifica quindi ciò che quella
     * transazione non nasconde: che la ricerca emetta davvero un
     * `MATCH … AGAINST`, e che quel `MATCH` trovi il proprio indice — senza,
     * MariaDB rifiuterebbe la query invece di restituire zero righe, e il test
     * fallirebbe con un errore di SQL.
     */
    $fullText = array_filter(
        $eseguite,
        static fn (string $sql): bool => str_contains($sql, 'match (`events`.`description`) against'),
    );

    expect($fullText)->not->toBeEmpty();
});

it('trova un pezzo di parola nel titolo, che l\'indice full-text da solo non farebbe', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Concertone di fine estate']);

    $this->get('/cerca?q=concerton')
        ->assertOk()
        ->assertSee('Concertone di fine estate');
});

it('non mostra gli eventi già passati fra i risultati', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $category, '2026-08-01 21:00:00', event: ['title' => 'Fisarmonica di agosto']);

    $this->get('/cerca?q=fisarmonica')
        ->assertOk()
        ->assertDontSee('Fisarmonica di agosto')
        ->assertSee(__('search.empty.title', ['query' => 'fisarmonica']));
});

it('non mostra le bozze né gli eventi di un\'altra città', function (): void {
    $city = testCity();
    $altra = City::factory()->padova()->create(['name' => 'Vicenza', 'slug' => 'vicenza']);
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $bozza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Ocarina in bozza']);
    $bozza->event->update(['status' => EventStatus::Draft]);

    occurrenceAtLocal($altra, $category, '2026-09-06 21:00:00', event: ['title' => 'Ocarina vicentina']);

    $this->get('/cerca?q=ocarina')
        ->assertOk()
        ->assertDontSee('Ocarina in bozza')
        ->assertDontSee('Ocarina vicentina');
});

it('senza domanda propone da dove partire invece di restare spoglia', function (): void {
    $city = testCity();
    $category = testCategory(['name' => 'Musica dal vivo']);

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

    $this->get('/cerca')
        ->assertOk()
        ->assertSee(__('search.empty.prompt_title'))
        ->assertSee(__('search.empty.categories'))
        ->assertSee('Musica dal vivo')
        ->assertSee(__('ui.nav.weekend'))
        ->assertDontSee('Nessun evento');
});

it('propone solo le categorie che hanno davvero qualcosa in programma', function (): void {
    $city = testCity();
    $piena = testCategory(['name' => 'Musica dal vivo']);
    testCategory(['name' => 'Categoria deserta']);

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $piena, '2026-09-06 21:00:00');

    $this->get('/cerca?q=parolachenonesiste')
        ->assertOk()
        ->assertSee('Musica dal vivo')
        ->assertDontSee('Categoria deserta');
});

it('una ricerca libera non entra nell\'indice', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');

    $this->get('/cerca?q=qualcosa')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);
});
