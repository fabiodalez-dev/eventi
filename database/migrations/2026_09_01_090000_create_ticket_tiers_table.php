<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le fasce di prezzo di un evento — «Parterre in piedi, 49 €, esaurito»
 * accanto a «Secondo anello, 59 €, disponibile».
 *
 * **Perché sull'evento, con l'occorrenza facoltativa.** Il listino è
 * editoriale e stabile: i settori di un teatro sono gli stessi tutte le sere,
 * e chiederli data per data significherebbe non averli quasi mai su una
 * rassegna di dieci serate. La singola data può però smentirlo — l'anteprima a
 * prezzo unico, la replica con il parterre chiuso — ed è esattamente il
 * rapporto che lo schema ha già fra `events.price_*` e
 * `event_occurrences.price_override`: si riusa quello invece di inventarne un
 * secondo. `occurrence_id` nullo = listino dell'evento; valorizzato = listino
 * di quella data soltanto, che **sostituisce** il primo (non lo integra: una
 * fusione per nome sarebbe una regola che nessuno ricorda al terzo mese).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('occurrence_id')->nullable()->constrained('event_occurrences')->cascadeOnDelete();

            $table->string('name');
            // Nullo significa "prezzo non dichiarato": una fascia a ingresso
            // libero vale 0, che è un prezzo e non un'assenza.
            $table->decimal('price', 8, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->enum('status', ['available', 'sold_out', 'not_yet_on_sale', 'closed'])->default('available');
            $table->string('url')->nullable();
            $table->string('note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->datetimes();

            // Le due letture che la scheda evento fa davvero: il listino
            // dell'evento e quello di una data.
            $table->index(['event_id', 'occurrence_id', 'sort_order'], 'ticket_tiers_listing_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_tiers');
    }
};
