<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tre cose che mancavano al banco del locale.
 *
 * **Prenotazioni inserite dal personale.** Chi riceve una telefonata o una
 * persona al banco oggi non ha dove scriverla: `bookings.user_id` diventa
 * facoltativo, perché una prenotazione presa al telefono non ha un account
 * dietro, e `created_by` dice chi l'ha scritta. I recapiti della persona
 * stanno in `booker_data`, che è già cifrato.
 *
 * **Messaggi a chi ha prenotato.** `booking_messages` è la memoria di ciò che
 * il locale ha scritto ai prenotati di una data: senza, due collaboratori
 * potrebbero mandare lo stesso avviso due volte, e nessuno saprebbe cosa è
 * già stato detto.
 *
 * **Chi non si presenta.** Nessuna colonna nuova: `admission_tickets.status`
 * è già una stringa e accoglie `no_show`. Il valore lo scrive il comando
 * periodico, non la porta, e solo per le date in cui il locale ha davvero
 * registrato ingressi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('created_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('booking_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 160);
            $table->text('body');
            $table->boolean('include_waitlisted')->default(false);
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();

            $table->index(['occurrence_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_messages');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
