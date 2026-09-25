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

    public function backfill(): void
    {
        // Un consiglio non prova una partecipazione. Sono espliciti solo i post
        // «Parteciperò» o l'ultima azione «Ci vado» ancora pubblica.
        DB::table('saved_events')->where('visibility', 'public')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $attend = DB::table('community_posts')->where('saved_event_id', $row->id)->where('intent', 'attend')->exists();
                $explicit = DB::table('activity_log')->where('causer_id', $row->user_id)->where('event', 'attendance_public')
                    ->where('properties->saved_event_id', $row->id)->exists();
                if ($attend || $explicit) {
                    DB::table('community_attendances')->insertOrIgnore(['user_id' => $row->user_id, 'occurrence_id' => $row->occurrence_id,
                        'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('La separazione delle attività richiede il ripristino del backup del rilascio per tornare al modello precedente.');
    }
};
