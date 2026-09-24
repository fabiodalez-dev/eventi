<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I sondaggi fra amici: «quale di queste date ti va bene?».
 *
 * Il sondaggio vive di link, non di rubrica: chi propone manda l'indirizzo a
 * chi vuole, e chi lo riceve vota con il proprio account. Un voto anonimo
 * sarebbe più comodo da mandare e impossibile da moderare — e trasformerebbe
 * un accordo fra amici in un sondaggio pubblico falsificabile.
 *
 * Nessuna tabella per gli inviti: l'invito è il link. La partecipazione nasce
 * quando qualcuno vota, e si cancella quando esce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_polls', function (Blueprint $table): void {
            $table->id();
            // Il codice sta nell'indirizzo: lungo abbastanza da non essere indovinabile.
            $table->string('token', 32)->collation('ascii_bin')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamp('closes_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });

        Schema::create('event_poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['event_poll_id', 'occurrence_id']);
        });

        Schema::create('event_poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_poll_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            // Un voto per persona e per data: il doppio tocco su un telefono
            // lento non deve valere due volte.
            $table->unique(['event_poll_option_id', 'user_id']);
            $table->index(['event_poll_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_poll_votes');
        Schema::dropIfExists('event_poll_options');
        Schema::dropIfExists('event_polls');
    }
};
