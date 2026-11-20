<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Obiettivo di visualizzazioni e distribuzione per le campagne singole, quelle
 * messe dalla redazione senza abilitazione: per loro l'unità è la riga stessa.
 *
 * `impressions_goal` non sostituisce `impressions_cap`. Il tetto è un massimo
 * e basta; l'obiettivo è un impegno, e anche un massimo: arrivata
 * all'obiettivo, la campagna smette di comparire (`Sponsorship::visible()`).
 *
 * Nessuna modifica alle due colonne ENUM esistenti (`placement`, `status`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsorships', function (Blueprint $table): void {
            $table->unsignedInteger('impressions_goal')->nullable()->after('impressions_cap');
            $table->string('pacing', 16)->default('even')->after('impressions_goal');
        });
    }

    public function down(): void
    {
        Schema::table('sponsorships', function (Blueprint $table): void {
            $table->dropColumn(['impressions_goal', 'pacing']);
        });
    }
};
