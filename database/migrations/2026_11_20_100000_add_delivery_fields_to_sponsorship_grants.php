<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'abilitazione diventa l'unità che si vende: peso, priorità, obiettivo di
 * visualizzazioni e distribuzione stanno su di lei, non più sulle righe evento.
 *
 * **Perché.** In modalità automatica ogni evento del committente era una riga
 * con peso 1 nella ruota: un locale con quaranta eventi occupava quaranta
 * posti contro uno di chi aveva comprato un evento solo, allo stesso prezzo.
 * Con peso e priorità sull'abilitazione la scelta avviene in due passi, prima
 * l'unità e poi l'evento dentro l'unità.
 *
 * `pacing` è una stringa e non un ENUM: aggiungere un caso a un ENUM MySQL
 * vuol dire un'altra migrazione, ed è già successo con `placement` e `status`.
 *
 * I dati esistenti: peso e priorità dell'abilitazione prendono il massimo delle
 * sue righe ancora in vita (oggi quasi sempre 1 e 0, salvo modifiche a mano).
 * Le righe figlie non si toccano, quindi `down()` non perde niente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsorship_grants', function (Blueprint $table): void {
            $table->unsignedSmallInteger('weight')->default(1)->after('placement');
            $table->unsignedSmallInteger('priority')->default(0)->after('weight');
            /* Vuoto = prodotto a periodo, senza obiettivo. */
            $table->unsignedInteger('impressions_goal')->nullable()->after('priority');
            $table->string('pacing', 16)->default('even')->after('impressions_goal');
        });

        /*
         * Una sola istruzione, nel database: le abilitazioni sono poche, ma non
         * c'è motivo di portarle in PHP una per una. I limiti 1–100 e 0–1000
         * sono quelli che il pannello impone alle righe; una riga scritta a
         * mano fuori dai limiti non li porta sull'abilitazione.
         */
        DB::statement(<<<'SQL'
            UPDATE sponsorship_grants AS g
            INNER JOIN (
                SELECT sponsorship_grant_id, MAX(weight) AS max_weight, MAX(priority) AS max_priority
                FROM sponsorships
                WHERE sponsorship_grant_id IS NOT NULL AND deleted_at IS NULL
                GROUP BY sponsorship_grant_id
            ) AS s ON s.sponsorship_grant_id = g.id
            SET g.weight = LEAST(100, GREATEST(1, s.max_weight)),
                g.priority = LEAST(1000, GREATEST(0, s.max_priority))
            SQL);
    }

    public function down(): void
    {
        Schema::table('sponsorship_grants', function (Blueprint $table): void {
            $table->dropColumn(['weight', 'priority', 'impressions_goal', 'pacing']);
        });
    }
};
