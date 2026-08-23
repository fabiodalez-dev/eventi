<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indici full-text per la ricerca del sito (`/cerca`).
 *
 * Laravel Scout con driver `database` traduce la ricerca in una query sulle
 * colonne dichiarate dal modello. Le colonne brevi (titolo, sottotitolo, nome
 * del locale) restano su `LIKE`, che trova anche i pezzi di parola; le
 * **descrizioni** passano invece per `MATCH … AGAINST`, perché un `LIKE
 * '%…%'` su un testo lungo non può usare alcun indice e obbliga a leggere
 * l'intera tabella a ogni ricerca.
 *
 * Senza questi indici MariaDB non risponde "nessun risultato": rifiuta la
 * query con «Can't find FULLTEXT index matching the column list».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->fullText('description', 'events_description_fulltext');
        });

        Schema::table('venues', function (Blueprint $table): void {
            $table->fullText('description', 'venues_description_fulltext');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropFullText('events_description_fulltext');
        });

        Schema::table('venues', function (Blueprint $table): void {
            $table->dropFullText('venues_description_fulltext');
        });
    }
};
