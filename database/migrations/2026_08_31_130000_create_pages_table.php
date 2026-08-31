<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le pagine di §11.1 (`/pagine/{slug}`) e di §16: informativa privacy, cookie
 * policy, termini, chi siamo, contatti.
 *
 * Stanno **nel database e non in file Blade** per una ragione sola: un errore
 * in un'informativa privacy va corretto oggi, non al prossimo rilascio. Sono
 * anche gli unici testi del sito che una persona non tecnica deve poter
 * riscrivere per intero.
 *
 * Il corpo è **Markdown**, non HTML. Un editor ricco che salva HTML obbliga a
 * ripulirlo a ogni lettura e a fidarsi che la ripulitura non abbia buchi
 * (§16: «sanitizzazione HTML con whitelist»); il Markdown si converte al
 * momento della lettura scartando qualunque marcatura grezza, quindi non
 * esiste alcun percorso per cui uno `<script>` finisca in pagina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();

            // Lo slug è l'indirizzo: `/pagine/privacy`. Unico in tutto il
            // sistema perché queste pagine sono del sito, non di una città —
            // un'informativa privacy per provincia non esiste.
            $table->string('slug', 191)->unique();

            $table->string('title');

            // Il sommario compare sotto il titolo e diventa la meta
            // description quando `seo_description` è vuota: una pagina legale
            // senza descrizione, nei risultati di ricerca, mostra le prime
            // righe del testo di legge.
            $table->string('excerpt', 500)->nullable();

            $table->longText('body');

            $table->boolean('is_published')->default(false);

            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();

            // L'ordine nel piè di pagina. Non è l'ordine alfabetico: la
            // privacy viene prima di «chi siamo» perché è quella che si cerca.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->datetimes();

            $table->index(['is_published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
