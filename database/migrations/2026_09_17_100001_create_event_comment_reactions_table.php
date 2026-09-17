<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le reazioni a un commento.
 *
 * ## L'esclusività è nello schema, non nel codice
 *
 * Le reazioni sono alternative fra loro: chi mette il cuore e poi sceglie
 * un'altra faccina, la prima la perde. Il modo fragile di ottenerlo è
 * ricordarsi, in ogni punto che scrive, di cancellare prima le altre — e
 * basta un punto dimenticato perché un utente resti con due reazioni.
 *
 * Il modo solido è `unique(event_comment_id, user_id)`: **una riga sola per
 * persona per commento**, per costruzione. Cambiare reazione diventa un
 * `UPDATE` della colonna `type`, e nessuna dimenticanza futura può produrre
 * uno stato che il database rifiuta di contenere.
 *
 * È anche ciò che rende verificabile la regola «un "mi piace" tolto e rimesso
 * non rinotifica»: si guarda se la riga esisteva già.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_comment_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->datetimes();

            /* Il vincolo che rende le reazioni alternative fra loro. */
            $table->unique(['event_comment_id', 'user_id']);

            /* «Che cosa ho messo io» sulle righe di una pagina. */
            $table->index(['user_id', 'event_comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_comment_reactions');
    }
};
