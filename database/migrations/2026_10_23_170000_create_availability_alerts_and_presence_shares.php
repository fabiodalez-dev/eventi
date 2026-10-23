<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Due promesse brevi fatte a una persona.
 *
 * `availability_alerts`: «avvisami se questa data esaurita torna
 * disponibile». Una riga per persona e data; si cancella quando l'avviso
 * parte, perché è un avviso di una volta.
 *
 * `presence_shares`: il link «sono qui», con la posizione del locale e non
 * quella del telefono. Scade da solo a fine serata e si revoca quando si
 * vuole; `display_name` e `note` sono scritti dalla persona per chi riceve
 * il link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'occurrence_id']);
            $table->index('occurrence_id');
        });

        Schema::create('presence_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->string('token', 32)->unique();
            $table->string('display_name', 60)->nullable();
            $table->string('note', 200)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'occurrence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presence_shares');
        Schema::dropIfExists('availability_alerts');
    }
};
