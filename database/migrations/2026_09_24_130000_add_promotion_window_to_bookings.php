<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La finestra di conferma di chi viene promosso dalla lista d'attesa.
 *
 * Finora la promozione era immediata e definitiva: il posto restava assegnato
 * anche a chi non avrebbe mai letto l'email, mentre dietro c'era qualcuno che
 * lo voleva. Con una scadenza, il posto torna in circolo da solo.
 *
 * È nullo in due casi diversi e non confondibili: prenotazione mai passata
 * dalla lista d'attesa, oppure promozione già confermata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('promotion_expires_at')->nullable()->after('status');
            $table->index(['promotion_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['promotion_expires_at']);
            $table->dropColumn('promotion_expires_at');
        });
    }
};
