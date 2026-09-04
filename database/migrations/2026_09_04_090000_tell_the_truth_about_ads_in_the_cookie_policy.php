<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La Cookie Policy diceva una cosa che non è più vera.
 *
 * «I cookie che questo sito usa sono tre, e nessuno serve a profilarti»: era
 * esatto quando è stato scritto. Poi sono arrivate le sponsorizzazioni, e da
 * quando la scelta degli annunci guarda le categorie degli eventi salvati
 * quella frase dichiara il falso in un'informativa legale.
 *
 * **È il modo tipico in cui una policy diventa sbagliata**: non perché
 * qualcuno scriva una bugia, ma perché il sito cambia e il testo resta. Ed è
 * la ragione per cui l'elenco dei cookie è passato a un registro — un testo
 * scritto a mano non si accorge di essere invecchiato.
 *
 * Qui si tocca solo la frase di sintesi e solo se è ancora quella originale:
 * se la redazione l'ha già riscritta, la sua versione vale più della nostra.
 */
return new class extends Migration
{
    private const VECCHIO = 'I cookie che questo sito usa sono tre, e nessuno serve a profilarti.';

    private const NUOVO = 'Quali cookie usa questo sito, e cosa guardano gli annunci per scegliere cosa mostrarti.';

    public function up(): void
    {
        DB::table('pages')
            ->where('slug', 'cookie')
            ->where('excerpt', self::VECCHIO)
            ->update(['excerpt' => self::NUOVO]);
    }

    public function down(): void
    {
        /* Non si torna indietro: la frase di prima era vera per un sito che
           non esiste più. */
    }
};
