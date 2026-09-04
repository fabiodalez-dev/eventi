<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il registro dei cookie: cosa il sito deposita, per quale finalità, per
 * quanto tempo.
 *
 * **Perché in una tabella e non in un file di configurazione.** I pacchetti
 * che risolvono questo problema — whitecube, devrabiul — li fanno dichiarare
 * in `config/`, e per uno sviluppatore è comodo. Ma un elenco di cookie non è
 * una scelta tecnica: è il contenuto di un'informativa, cambia quando cambia
 * uno strumento, e chi se ne accorge non è chi ha accesso a un file da
 * ridistribuire. Qui si aggiunge dal pannello, e la Cookie Policy si aggiorna
 * da sé.
 *
 * **Perché non descrive il comportamento del sito, lo dichiara.** Questa
 * tabella non impedisce a nulla di depositare cookie: quello lo fa il codice
 * del consenso. Serve a dire cosa c'è, ed è per questo che va tenuta onesta a
 * mano — un elenco generato automaticamente non esiste, perché nessuna
 * libreria sa perché un cookie è stato messo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cookie_declarations', function (Blueprint $table): void {
            $table->id();

            /* La finalità: è ciò che decide in quale riquadro compare e se il
               consenso serve. I valori sono quelli di `ConsentCategory`. */
            $table->string('category', 32)->index();

            $table->string('name');
            /* Chi lo deposita: noi, oppure un servizio esterno. La distinzione
               è quella che il GDPR chiede di rendere evidente. */
            $table->string('provider')->nullable();
            $table->string('purpose');
            /* Scritta com'è, non in secondi: «un anno», «la sessione», «fino
               al logout». È testo per una persona, non un intervallo da
               calcolare. */
            $table->string('duration', 64)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->datetimes();

            /* Due righe con lo stesso nome nella stessa finalità sono un
               errore di inserimento, non un caso da gestire. */
            $table->unique(['category', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookie_declarations');
    }
};
