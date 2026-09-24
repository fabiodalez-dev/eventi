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
            $table->dateTime('sent_at');
            $table->datetimes();

            $table->unique(['venue_id', 'user_id', 'month'], 'venue_monthly_reports_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_monthly_reports');
    }
};
