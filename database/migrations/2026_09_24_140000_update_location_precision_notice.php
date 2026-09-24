<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L'informativa diceva che le coordinate sono arrotondate a due decimali.
 *
 * Dal 24/09/2026 non lo sono più (`docs/DECISIONS.md`), e una promessa che
 * resta scritta dopo essere diventata falsa è peggio di una promessa non
 * fatta: chi la legge decide in base a quella. La correzione parte con lo
 * stesso rilascio che cambia il dato, non dopo.
 */
return new class extends Migration
{
    /* Con `\s+` fra le parole e non uno spazio: la stessa frase esiste su una
       riga sola nelle pagine scritte dalla migrazione del 15/09 e spezzata su
       più righe in quelle nate dal seeder. Cercare lo spazio singolo avrebbe
       corretto le prime e lasciato le seconde, senza che nulla lo segnalasse. */
    private const VECCHIO = '/solo\s+l\'ultima\s+posizione\s+approssimata\s+\(coordinate\s+arrotondate\s+a\s+due\s+decimali,\s+circa\s+un\s+chilometro\),\s+cifrata\s+nel\s+cookie/u';

    private const NUOVO = "solo l'ultima posizione, con le coordinate che il dispositivo ci comunica, cifrata nel cookie";

    private const PRECISAZIONE = 'Le coordinate non sono arrotondate: servono a calcolare la distanza da un locale, e un\'approssimazione di un chilometro sbaglierebbe proprio la domanda a cui la funzione risponde. Nel tuo account la posizione sta in due forme: una cifrata e una in chiaro, che e\' quella su cui il database calcola la distanza — senza, ogni invio dovrebbe decifrare la posizione di tutti uno per uno. Sono lo stesso valore, scadono insieme e si cancellano insieme. Conserviamo quella posizione e nient\'altro.';

    public function up(): void
    {
        foreach (DB::table('pages')->get() as $page) {
            $body = (string) $page->body;

            $corretto = preg_replace(self::VECCHIO, self::NUOVO, $body);

            if ($corretto === null || $corretto === $body) {
                continue;
            }

            $body = $corretto;

            /* La precisazione va accanto alla frase che ha appena smesso di
               dirlo, non in fondo alla pagina: chi legge quel paragrafo deve
               trovarla lì, non doverla cercare. */
            $body = (string) preg_replace(
                '/Nessuna\s+cronologia\s+e\s+nessun\s+uso\s+pubblicitario\./u',
                'Nessuna cronologia e nessun uso pubblicitario. '.str_replace('$', '\$', self::PRECISAZIONE),
                $body,
                1,
            );

            DB::table('pages')->where('id', $page->id)->update(['body' => $body, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Un'informativa corretta resta corretta anche se l'applicazione torna indietro.
    }
};
