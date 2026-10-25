<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Avvisami quando aprono le prenotazioni»: una persona, una data con
 * l'apertura delle prenotazioni ancora davanti. Come `availability_alerts`
 * è un avviso di una volta: la riga sparisce quando l'avviso parte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_opening_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'occurrence_id']);
            $table->index('occurrence_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_opening_alerts');
    }
};
