<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le domande del gestore a chi prenota, e le risposte.
 *
 * `event_occurrences.booking_questions` tiene fino a cinque domande per data,
 * accanto a `booking_fields`. `bookings.booking_answers` è cifrata come
 * `booker_data` e porta con sé il testo della domanda: se il gestore la cambia
 * dopo, la risposta resta leggibile per quello che era stato chiesto. Le
 * risposte si cancellano trenta giorni dopo la data (`ticketing:prune-answers`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->json('booking_questions')->nullable();
        });
        Schema::table('bookings', function (Blueprint $table): void {
            $table->text('booking_answers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('booking_answers');
        });
        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->dropColumn('booking_questions');
        });
    }
};
