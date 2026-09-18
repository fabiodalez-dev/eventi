<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Porta nelle informative già installate la frase sull'impronta trattenuta per
 * gli account sospesi e cancellati, e corregge quella sulla revoca: dal batch 1
 * la revoca segna come usati i codici in attesa invece di cancellare le
 * richieste, che restano fino alla pulizia dopo trenta giorni. Le installazioni
 * nuove ricevono entrambe da `community.privacy_notice`: le guardie su
 * str_contains le lasciano intatte, e una seconda esecuzione non cambia nulla.
 */
return new class extends Migration
{
    private const RETENTION = "Se un account viene sospeso per abuso e poi cancellato, conserviamo soltanto l'impronta crittografica del numero WhatsApp e la data della sospensione, per impedire che lo stesso numero venga usato per una nuova iscrizione; il numero invece viene cancellato.";

    private const ANCHOR = 'senza codici OTP né copie dei testi ritirati.';

    private const REVOKE_BEFORE = 'la revoca elimina numero e richieste dal nostro database e nasconde i contenuti social.';

    private const REVOKE_AFTER = 'la revoca cancella il numero dal tuo account, rende inutilizzabili i codici in attesa e nasconde i contenuti social, mentre le richieste già fatte restano nello storico fino alla sua pulizia automatica dopo trenta giorni.';

    public function up(): void
    {
        foreach (DB::table('pages')->where('slug', 'privacy')->get() as $page) {
            $original = (string) $page->body;
            // Senza la sezione community non c'è dove appoggiare la frase: la aggiunge 2026_09_18_110000.
            if (! str_contains($original, '## Community e verifica WhatsApp')) {
                continue;
            }
            // La frase nuova non contiene quella vecchia: sostituirla due volte non cambia nulla.
            $body = str_replace(self::REVOKE_BEFORE, self::REVOKE_AFTER, $original);
            if (! str_contains($body, self::RETENTION)) {
                // Subito dopo il paragrafo su export e cancellazione; se la redazione l'ha riscritto, in coda.
                $body = str_contains($body, self::ANCHOR)
                    ? preg_replace('/'.preg_quote(self::ANCHOR, '/').'/', self::ANCHOR."\n\n".self::RETENTION, $body, 1) ?? $body
                    : rtrim($body)."\n\n".self::RETENTION."\n";
            }
            if ($body !== $original) {
                DB::table('pages')->where('id', $page->id)->update(['body' => $body, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // L'informativa resta: descrive dati che possono essere già stati trattenuti.
    }
};
