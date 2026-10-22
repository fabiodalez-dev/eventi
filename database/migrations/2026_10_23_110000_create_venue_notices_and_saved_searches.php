<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Due modi nuovi di dire «avvisami».
 *
 * **Avvisi del locale a chi lo segue.** Un locale chiude per ferie, cambia
 * gestione, riapre dopo i lavori: oggi lo scrive sui social e chi lo segue
 * qui non lo sa. L'avviso è un testo breve con un tipo e una data di fine
 * facoltativa; compare sulla scheda pubblica finché vale e parte una volta
 * verso chi segue il locale con le notifiche accese.
 *
 * **Ricerche salvate.** «Avvisami quando c'è un concerto jazz gratis»: i
 * filtri della ricerca si conservano come li scrive l'URL, e un comando
 * periodico guarda cosa è stato pubblicato da quando ha controllato l'ultima
 * volta. Niente di nuovo, niente mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 24);
            $table->string('body', 500);
            $table->date('until')->nullable();
            $table->timestamps();

            $table->index(['venue_id', 'created_at']);
        });

        Schema::create('saved_searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('filters');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('venue_notices');
    }
};
