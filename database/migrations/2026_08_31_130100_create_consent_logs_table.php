<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il «log del consenso» che §16 richiede: la prova di quando una scelta è
 * stata espressa, quale era, e a quale versione dell'informativa si riferiva.
 *
 * ## Quello che questa tabella non contiene, di proposito
 *
 * Non contiene l'indirizzo IP. Un registro che serve a dimostrare un consenso
 * non ha bisogno di sapere da dove è arrivato: gli basta poter riconoscere che
 * *quella* scelta appartiene a *quel* browser, e a questo serve
 * `consent_id` — un numero casuale generato qui, conservato nel cookie
 * insieme alla scelta e in nessun altro posto. Raccogliere l'IP per provare il
 * rispetto della privacy sarebbe l'unica raccolta di dati personali introdotta
 * da questa funzione, e §16 chiede di raccogliere il minimo.
 *
 * `user_id` c'è ma è quasi sempre nullo: solo se la scelta viene espressa
 * mentre una sessione è aperta. Non lo si va a cercare dopo — una scelta fatta
 * da anonimo resta di quel browser anche se un mese dopo quella persona si
 * registra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_logs', function (Blueprint $table): void {
            $table->id();

            // L'identificativo del browser che ha scelto. UUID versione 4:
            // non deriva da niente e non identifica nessuno fuori da questa
            // tabella e dal cookie che lo porta.
            $table->uuid('consent_id');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // `accept_all|reject_all|custom` — App\Enums\ConsentAction.
            $table->enum('action', ['accept_all', 'reject_all', 'custom']);

            // La scelta per categoria: {"necessary": true, "statistics": false}.
            // Come mappa e non come colonne perché le categorie cambiano con le
            // finalità del sito, e una finalità in più non deve essere una
            // migration.
            $table->json('choices');

            // La versione dell'informativa vigente al momento della scelta:
            // senza, un registro non prova nulla — dice che qualcuno ha detto
            // di sì, non a che cosa.
            $table->string('policy_version', 32);

            $table->datetimes();

            $table->index('consent_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_logs');
    }
};
