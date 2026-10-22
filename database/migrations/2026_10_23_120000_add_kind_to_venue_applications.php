<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tre richieste diverse nella stessa coda.
 *
 * Finora `venue_applications` conteneva un solo tipo di richiesta: «apritemi
 * un locale nuovo». Ne arrivano altre due che hanno gli stessi campi e lo
 * stesso percorso di approvazione: «questo locale a catalogo è mio» (una
 * rivendicazione, con `venue_id` già pieno) e «organizzo eventi ma non ho
 * una sede» (un organizzatore). La colonna dice quale delle tre è, così la
 * redazione la legge per quello che è e l'approvazione fa la cosa giusta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_applications', function (Blueprint $table): void {
            $table->string('kind', 16)->default('venue')->after('id')->index();
            $table->foreignId('organizer_id')->nullable()->after('venue_id')->constrained('organizers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('venue_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organizer_id');
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
