<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ripara le partecipazioni che la separazione non ha riconosciuto.
 *
 * La migrazione `separate_community_attendance` è stata rilasciata con un
 * criterio che si fermava ai post «Parteciperò» e al registro. Ma il vecchio
 * «Ci vado» non creava alcun post — rendeva pubblico il salvataggio e scriveva
 * in `activity_log` — e la riga `attendance_public` nasce con quella stessa
 * modifica, quindi in produzione non ne esisteva nemmeno una. Risultato: dei
 * 124 gesti da migrare ne sono passati 21, e dalle schede è sparito l'85% di
 * chi ci va.
 *
 * Il criterio corretto sta ora nella migrazione originale, il che sistema le
 * installazioni nuove; questa serve a chi l'ha già eseguita. Nessun dato era
 * andato perduto: `saved_events` era intatto, e la tabella delle partecipazioni
 * è derivata da lì.
 *
 * È **ripetibile** e non ha un verso contrario da scrivere: inserisce le righe
 * mancanti con `insertOrIgnore`, quindi su un'installazione già corretta non fa
 * niente. Il `down()` non cancella nulla di proposito — disfare una riparazione
 * significherebbe ricreare un guasto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('saved_events')->where('visibility', 'public')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                // Un consiglio non prova una partecipazione: resta fuori chi ha
                // un post che non sia «Parteciperò».
                $intenti = DB::table('community_posts')->where('saved_event_id', $row->id)->pluck('intent');
                if ($intenti->isEmpty() || $intenti->contains('attend')) {
                    DB::table('community_attendances')->insertOrIgnore(['user_id' => $row->user_id, 'occurrence_id' => $row->occurrence_id,
                        'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
                }
            }
        });
    }

    public function down(): void
    {
        // Volutamente vuoto: vedi il commento in testa.
    }
};
