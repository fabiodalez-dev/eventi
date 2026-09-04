<?php

declare(strict_types=1);

use App\Models\Page;
use Illuminate\Database\Migrations\Migration;

/**
 * Toglie dalla Cookie Policy la tabella dei cookie scritta a mano.
 *
 * Da oggi l'elenco viene dal registro (`cookie_declarations`) e la pagina lo
 * disegna da lì. Lasciare anche quella nel testo darebbe due elenchi nella
 * stessa pagina — e siccome uno dei due non si aggiorna, prima o poi si
 * contraddicono. In un'informativa legale è il difetto peggiore: non manca
 * un'informazione, ce ne sono due che dicono cose diverse.
 *
 * **Perché una migrazione e non il seeder.** `PageSeeder` non sovrascrive le
 * pagine che esistono, di proposito: i testi stanno nel database perché la
 * redazione possa correggerli, e un seeder distruttivo cancellerebbe quel
 * lavoro a ogni rilascio. Questa è invece una modifica strutturale che deve
 * arrivare una volta sola.
 *
 * Toglie **solo** il blocco che riconosce, e se non lo trova non fa niente:
 * se qualcuno ha già riscritto quella parte, la sua versione resta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pagina = Page::query()->where('slug', 'cookie')->first();

        if ($pagina === null) {
            return;
        }

        $corpo = (string) $pagina->body;
        $inizio = mb_strpos($corpo, '## Cookie tecnici, sempre presenti');

        if ($inizio === false) {
            return;
        }

        /* Il blocco finisce dove comincia il paragrafo successivo, che il
           testo originale apre sempre con «Sono cookie tecnici». Cercare la
           fine invece di contare le righe rende la sostituzione stabile
           rispetto a una riga aggiunta nel mezzo. */
        $fine = mb_strpos($corpo, 'Puoi comunque cancellarli dalle impostazioni', $inizio);

        if ($fine === false) {
            return;
        }

        $fine = mb_strpos($corpo, "\n\n", $fine);
        $coda = $fine === false ? '' : mb_substr($corpo, $fine);

        $pagina->forceFill([
            'body' => mb_substr($corpo, 0, $inizio).ltrim($coda),
        ])->save();
    }

    public function down(): void
    {
        /* Non si ricostruisce: il testo tolto è duplicato di un registro che
           adesso è la fonte, e rimetterlo ricreerebbe la contraddizione che
           questa migrazione esiste per togliere. */
    }
};
