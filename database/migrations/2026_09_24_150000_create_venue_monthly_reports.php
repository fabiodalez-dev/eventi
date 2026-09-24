<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chi ha già ricevuto il rapporto di quale mese.
 *
 * Il comando gira una volta al mese e manda una email a ogni referente di ogni
 * locale. Senza questo registro, un'interruzione a metà — o un secondo
 * lancio a mano, che è esattamente ciò che si fa quando il primo sembra andato
 * storto — rimanda il rapporto a chi l'ha già ricevuto. Una email di troppo in
 * un rapporto mensile non è un fastidio piccolo: è la ragione per cui la gente
 * spegne le notifiche.
 *
 * Il vincolo unico è il guardiano, e `insertOrIgnore` è ciò che rende la presa
 * in carico atomica anche fra due esecuzioni contemporanee: manda chi riesce a
 * scrivere la riga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_monthly_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Il primo giorno del mese riportato: una data e non una stringa, così si ordina e si confronta.
            $table->date('month');
            /*
             * Due istanti e non uno. La presa in carico si scrive prima di
             * spedire, la spedizione dopo: se il processo muore in mezzo —
             * server riavviato, memoria finita — con un solo `sent_at` la riga
             * direbbe «mandato» di un rapporto che non è mai partito, e nessun
             * lancio successivo lo recupererebbe. Una presa in carico vecchia e
             * senza invio si può ripulire; un invio scritto non si tocca.
             */
            $table->dateTime('claimed_at');
            $table->dateTime('sent_at')->nullable();
            $table->datetimes();

            $table->unique(['venue_id', 'user_id', 'month'], 'venue_monthly_reports_unique');
            // Per ritrovare in fretta le prese in carico rimaste a metà.
            $table->index(['sent_at', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_monthly_reports');
    }
};
