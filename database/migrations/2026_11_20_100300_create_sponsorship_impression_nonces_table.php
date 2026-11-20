<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I numeri casuali dei gettoni già contati: è la deduplica delle
 * visualizzazioni.
 *
 * `INSERT IGNORE` sulla chiave primaria: se la riga entra la visualizzazione
 * si conta, altrimenti è un doppione. È atomico su MariaDB senza Redis e senza
 * lock applicativi.
 *
 * Una riga per visualizzazione contata, potata ogni ora dopo 6 ore (la vita
 * del gettone): a regime restano qualche centinaio di righe, non uno storico.
 * Il numero casuale appartiene a una visita di pagina, non a una persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_impression_nonces', function (Blueprint $table): void {
            /* 8 byte casuali in esadecimale. */
            $table->char('nonce', 16)->primary();
            $table->foreignId('sponsorship_id')->constrained()->cascadeOnDelete();
            $table->string('placement', 32);
            $table->dateTime('seen_at')->index();
            $table->dateTime('clicked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_impression_nonces');
    }
};
