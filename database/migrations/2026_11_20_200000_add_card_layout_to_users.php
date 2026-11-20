<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La vista degli elenchi sul telefono (`full`, `grid`, `list`) entra nel
 * profilo, come il tema: scelta sul sito dal telefono, la ritrova l'app, e
 * viceversa. Vuota vuol dire nessuna scelta: vale quella del dispositivo,
 * altrimenti le card a tutta riga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('card_layout', 8)->nullable()->after('appearance');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('card_layout');
        });
    }
};
