<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le misure di una campagna, giorno per giorno.
 *
 * **Perche' non bastavano i due contatori sulla campagna.** `impressions` e
 * `clicks` sono cumulativi: dicono un totale e nient'altro. «Quante
 * visualizzazioni a novembre?» non ha risposta; una campagna sospesa e ripresa
 * mescola due periodi in un numero solo; e un errore che gonfia il contatore
 * e' irreversibile, perche' non si sa quando sia entrato. Si sta fatturando su
 * un numero che non si puo' difendere se qualcuno lo contesta.
 *
 * Qui ogni riga e' un giorno di una campagna, e il totale diventa una somma
 * verificabile invece di una cifra da credere sulla parola.
 *
 * **I contatori sulla campagna restano dove sono.** Sono comodi e veloci per
 * l'elenco del pannello, dove serve un totale e non una storia. Semplicemente
 * smettono di essere l'unica verita'.
 *
 * **Una riga per campagna e giorno**, scritta in `upsert`: non cresce con il
 * traffico ma con i giorni, ed e' la differenza fra una tabella che si
 * interroga in un istante e una che a fine anno va archiviata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_daily_stats', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sponsorship_id')->constrained('sponsorships')->cascadeOnDelete();

            /*
             * `date` e non `datetime`: e' il giorno, e va confrontato con
             * altri giorni. Tenere un orario inviterebbe a raggruppare per ora
             * — cosa che questa tabella non sa fare e non deve promettere.
             */
            $table->date('day');

            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);

            $table->timestamps();

            /*
             * La chiave del giorno: la scrittura e' un `upsert` su questa
             * coppia, quindi due richieste simultanee sullo stesso giorno non
             * creano due righe.
             */
            $table->unique(['sponsorship_id', 'day']);

            /* L'ordine e' quello delle interrogazioni vere: «questa campagna,
               in questo intervallo». */
            $table->index(['sponsorship_id', 'day'], 'sponsorship_stats_range_index');

            /* E questo per le somme di giornata su tutte le campagne, che e'
               cio' che guarda il riepilogo dell'amministrazione. */
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_daily_stats');
    }
};
