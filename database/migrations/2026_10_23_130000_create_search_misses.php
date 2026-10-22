<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le ricerche che non hanno trovato niente.
 *
 * È la domanda che il catalogo non copre, scritta da chi la esprime: una
 * categoria che manca, un locale mai inserito, un nome scritto in un altro
 * modo. Si conserva il termine normalizzato con quante volte e quando, mai
 * chi l'ha cercato: serve a decidere cosa aggiungere, non a profilare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_misses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('term', 120);
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_searched_at');
            $table->timestamp('last_searched_at');
            $table->timestamps();

            $table->unique(['city_id', 'term']);
            $table->index(['city_id', 'count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_misses');
    }
};
