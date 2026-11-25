<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lo storico delle pubblicazioni della redazione non deve sparire con la
 * pulizia notturna dei pacchetti (`SocialBatch::prunable`). Prima la chiave
 * esterna era `cascadeOnDelete`: cancellato un pacchetto vecchio se ne
 * andavano anche le sue pubblicazioni concluse. Ora la colonna è nullable e
 * la chiave è `nullOnDelete`: la pubblicazione resta, senza il pacchetto (e
 * quindi senza immagini).
 *
 * Tre passi separati perché MySQL non cambia una colonna che sta in una
 * chiave esterna: prima si toglie la chiave, poi si cambia la colonna, poi si
 * rimette la chiave con lo stesso nome (MySQL e MariaDB riusano l'indice che
 * è rimasto). `uuid()` dà lo stesso tipo della colonna di partenza sullo
 * stesso server (`uuid` nativo su MariaDB recente, `char(36)` su MySQL), così
 * resta uguale a `social_batches.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_publications', function (Blueprint $table): void {
            $table->dropForeign(['social_batch_id']);
        });
        Schema::table('social_publications', function (Blueprint $table): void {
            $table->uuid('social_batch_id')->nullable()->change();
        });
        Schema::table('social_publications', function (Blueprint $table): void {
            $table->foreign('social_batch_id')->references('id')->on('social_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Le pubblicazioni rimaste senza pacchetto, con la regola di prima, non ci sarebbero più.
        DB::table('social_publications')->whereNull('social_batch_id')->delete();
        Schema::table('social_publications', function (Blueprint $table): void {
            $table->dropForeign(['social_batch_id']);
        });
        Schema::table('social_publications', function (Blueprint $table): void {
            $table->uuid('social_batch_id')->nullable(false)->change();
        });
        Schema::table('social_publications', function (Blueprint $table): void {
            $table->foreign('social_batch_id')->references('id')->on('social_batches')->cascadeOnDelete();
        });
    }
};
