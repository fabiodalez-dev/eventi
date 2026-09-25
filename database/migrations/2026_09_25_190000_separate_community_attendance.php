<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'occurrence_id']);
            $table->index(['occurrence_id', 'user_id']);
        });
        $this->backfill();
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->dropForeign(['saved_event_id']);
        });
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('saved_event_id')->nullable()->change();
            $table->foreign('saved_event_id')->references('id')->on('saved_events')->nullOnDelete();
            $table->unique(['user_id', 'occurrence_id']);
        });
    }

    /**
     * Che cosa, nel vecchio modello, era davvero una partecipazione.
     *
     * Un consiglio non la prova: chi scrive «ci consiglio questo posto» non ha
     * detto che ci va. Ma il criterio non può essere il solo post «Parteciperò»,
     * perché il vecchio «Ci vado» **non creava alcun post**: rendeva pubblico il
     * salvataggio e scriveva nel registro, e basta (`attendance()` prima di
     * questa separazione). La sua firma è quindi *pubblico e senza post*.
     *
     * Sui dati di produzione i tre insiemi chiudono esatti: 140 salvataggi
     * pubblici = 103 senza post + 21 con «Parteciperò» + 16 con un consiglio.
     * Fermarsi ai post avrebbe migrato 21 partecipazioni su 124, cancellando
     * dalle schede l'85% di chi ci va.
     *
     * Il registro resta come terza via, per le installazioni dove esiste: la
     * riga `attendance_public` è nata con questa stessa modifica, quindi in
     * produzione non ne esiste nemmeno una e da sola non avrebbe salvato nulla.
     */
    public function backfill(): void
    {
        DB::table('saved_events')->where('visibility', 'public')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $intenti = DB::table('community_posts')->where('saved_event_id', $row->id)->pluck('intent');
                $soloSalvataggio = $intenti->isEmpty();
                $attend = $intenti->contains('attend');
                $explicit = DB::table('activity_log')->where('causer_id', $row->user_id)->where('event', 'attendance_public')
                    ->where('properties->saved_event_id', $row->id)->exists();
                if ($soloSalvataggio || $attend || $explicit) {
                    DB::table('community_attendances')->insertOrIgnore(['user_id' => $row->user_id, 'occurrence_id' => $row->occurrence_id,
                        'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
                }
            }
        });
    }

    public function down(): void
    {
        // Su uno schema vuoto il reset è sicuro; con attività reali serve il backup.
        if (DB::table('community_attendances')->exists() || DB::table('community_posts')->exists()) {
            throw new RuntimeException('La separazione delle attività richiede il ripristino del backup del rilascio per tornare al modello precedente.');
        }
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->dropForeign(['saved_event_id']);
            $table->dropUnique(['user_id', 'occurrence_id']);
        });
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('saved_event_id')->nullable(false)->change();
            $table->foreign('saved_event_id')->references('id')->on('saved_events')->cascadeOnDelete();
        });
        Schema::dropIfExists('community_attendances');
    }
};
