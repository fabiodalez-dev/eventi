<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Le richieste di sponsorizzazione senza pagamento online.
 *
 * Lo stato `requested` non ha bisogno di niente: `status` e' una stringa, non
 * un ENUM. Servono invece due colonne: l'evento scelto al momento della
 * richiesta (o dell'acquisto), che la redazione mette in evidenza appena
 * approva, e il messaggio facoltativo di chi chiede. L'evento si stacca se
 * viene cancellato: l'ordine resta, con la sua storia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsorship_orders', function (Blueprint $table): void {
            $table->foreignId('event_id')->nullable()->after('organizer_id')->constrained()->nullOnDelete();
            $table->text('request_note')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('sponsorship_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('event_id');
            $table->dropColumn('request_note');
        });
    }
};
