<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Due campi sulla singola data.
 *
 * **`capacity`** è la capienza *di quella sera*, che non sempre è quella del
 * locale: una sala da 400 posti messa a platea seduta ne fa 180. Serve perché
 * senza un totale `capacity_left` — che esiste dal primo giorno e non è mai
 * stato mostrato — è un numero senza scala: «41 posti rimasti» non dice se è
 * tanto o poco. Nulla significa "quella del locale".
 *
 * **`highlight`** è l'etichetta di richiamo del disegno di riferimento:
 * «ULTIMI POSTI», «NUOVA DATA». Testo breve e scritto a mano, non calcolato:
 * un'etichetta dedotta da una percentuale mente il giorno in cui il locale
 * tiene i biglietti fuori vendita, e chi conosce la propria sala lo sa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->unsignedInteger('capacity')->nullable()->after('price_override');
            $table->string('highlight', 40)->nullable()->after('capacity_left');
        });
    }

    public function down(): void
    {
        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->dropColumn(['capacity', 'highlight']);
        });
    }
};
