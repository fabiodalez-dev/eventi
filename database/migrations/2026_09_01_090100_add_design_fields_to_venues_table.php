<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tre campi del locale che il disegno mostra e lo schema non aveva.
 *
 * **`zone`** è il quartiere, e non duplica `municipality`: in un capoluogo il
 * comune è lo stesso per tutti i locali, quindi come filtro non separa niente.
 * «Portello», «Arcella», «Santa Rita» separano. Indicizzata perché è un
 * filtro di lista (§11.3), non un dato da leggere e basta.
 *
 * **`transit`** è «come arrivare»: righe `{mode, text}`, sul locale perché
 * cambia col luogo e non con la serata.
 *
 * **`info`** è la scheda informativa del locale: righe `{label, value}` —
 * guardaroba, regole della sala, cosa si può portare dentro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->string('zone')->nullable()->after('municipality');
            $table->json('transit')->nullable()->after('opening_hours');
            $table->json('info')->nullable()->after('accessibility');

            $table->index(['city_id', 'zone']);
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->dropIndex(['city_id', 'zone']);
            $table->dropColumn(['zone', 'transit', 'info']);
        });
    }
};
