<?php

declare(strict_types=1);

use App\Enums\ConsentAction;
use App\Enums\ConsentCategory;
use App\Models\ConsentLog;
use App\Models\Page;
use Database\Seeders\PageSeeder;

/**
 * Il consenso per singola finalità.
 *
 * Con una sola finalità facoltativa bastavano due pulsanti: accetta tutto,
 * rifiuta tutto. Da quando ce ne sono due — statistiche e annunci — accettare
 * in blocco per averne una significa acconsentire anche all'altra, ed è
 * esattamente il consenso che l'articolo 7 del GDPR non considera libero.
 */
it('accetta una finalità e ne rifiuta un altra', function (): void {
    $this->post('/consenso', [
        'action' => ConsentAction::Custom->value,
        'categories' => [ConsentCategory::Statistics->value],
    ])->assertRedirect();

    $log = ConsentLog::query()->latest('id')->firstOrFail();

    expect($log->choices)->toBe([
        /* La necessaria è sempre vera e non compare fra le scelte: non è una
           concessione, è la condizione perché il sito funzioni. */
        ConsentCategory::Necessary->value => true,
        ConsentCategory::Statistics->value => true,
        ConsentCategory::Marketing->value => false,
    ]);
});

it('rifiuta tutto quando non si spunta niente', function (): void {
    $this->post('/consenso', [
        'action' => ConsentAction::Custom->value,
        'categories' => [],
    ])->assertRedirect();

    $log = ConsentLog::query()->latest('id')->firstOrFail();

    expect($log->choices[ConsentCategory::Statistics->value])->toBeFalse()
        ->and($log->choices[ConsentCategory::Marketing->value])->toBeFalse()
        /* Un modulo inviato senza spunte è una scelta, non un modulo vuoto:
           deve scrivere un rifiuto e far sparire il banner, altrimenti chi
           rifiuta tutto se lo ritrova a ogni pagina. */
        ->and($log->choices[ConsentCategory::Necessary->value])->toBeTrue();
});

it('non accetta finalità inventate', function (): void {
    /* Il valore arriva da un modulo pubblico: chi lo modifica non deve poter
       scrivere nel registro una finalità che non esiste. */
    $this->post('/consenso', [
        'action' => ConsentAction::Custom->value,
        'categories' => ['profilazione-selvaggia'],
    ])->assertSessionHasErrors('categories.0');

    expect(ConsentLog::query()->count())->toBe(0);
});

it('la pagina mostra una casella per ogni finalità facoltativa', function (): void {
    /* La Cookie Policy è una pagina del database, non una vista: senza il
       seeder l'indirizzo non esiste e il test fallisce con un 404 che sembra
       un problema di rotte. */
    (new PageSeeder)->run();

    $risposta = $this->get('/pagine/cookie');

    $risposta->assertOk();

    foreach (ConsentCategory::optional() as $categoria) {
        $risposta->assertSee('value="'.$categoria->value.'"', false);
    }

    /* La necessaria no: offrirla come casella suggerirebbe che si possa
       togliere, e chi la toglie non ottiene niente perché il sito la rimette. */
    $risposta->assertDontSee('value="'.ConsentCategory::Necessary->value.'"', false);
});

it('non dichiara di non profilare, perche gli annunci guardano i salvataggi', function (): void {
    (new PageSeeder)->run();

    $pagina = Page::query()->where('slug', 'cookie')->firstOrFail();
    $testo = $pagina->excerpt.' '.$pagina->body;

    /*
     * **Questa è una sentinella su un'affermazione legale.**
     *
     * La policy diceva «nessuno serve a profilarti» e «non ha pubblicità»:
     * erano vere quando furono scritte, e sono diventate false quando sono
     * arrivate le sponsorizzazioni con la scelta basata sugli eventi salvati.
     * Nessuno se n'era accorto, perché un testo non protesta mentre invecchia.
     *
     * Se qualcuno le rimette — copiando da una versione vecchia, o per
     * abitudine — questo test si accorge prima che lo faccia un'autorità.
     */
    expect($testo)->not->toContain('nessuno serve a profilarti')
        ->and($testo)->not->toContain('non ha pubblicità')
        /* E deve dire cosa succede davvero, non solo tacere su cosa non
           succede: un'informativa che omette non è più onesta di una che
           mente. */
        ->and($testo)->toContain('profilazione');
});
