<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I testi delle email, quando qualcuno li ha riscritti dal pannello.
 *
 * **Questa tabella contiene solo le differenze.** I testi veri vivono in
 * `lang/it/notifications.php` e restano il valore predefinito: qui finisce
 * una riga solo per ciò che è stato cambiato, e cancellarla riporta
 * all'originale. Il verso opposto — copiare tutti i testi nel database e
 * leggerli solo da lì — renderebbe possibile ritrovarsi con un'email vuota
 * per una riga cancellata per sbaglio, e toglierebbe il modo di sapere quale
 * fosse il testo di partenza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_texts', function (Blueprint $table): void {
            $table->id();

            /* La chiave completa, come si scrive in `__()`:
               `notifications.reminder.subject`. Unica, perché due righe per lo
               stesso testo sarebbero due verità in disaccordo. */
            $table->string('key')->unique();
            $table->text('value');

            /* Chi l'ha toccato: un testo che parla a nome del sito e cambia
               senza lasciare traccia è un guaio da spiegare. */
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_texts');
    }
};
