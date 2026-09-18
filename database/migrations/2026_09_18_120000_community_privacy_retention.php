<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Porta nelle informative già installate la frase sull'impronta trattenuta per
 * gli account sospesi e cancellati. Le installazioni nuove la ricevono da
 * `community.privacy_notice`: la guardia su str_contains le lascia intatte.
 */
return new class extends Migration
{
    private const RETENTION = "Se un account viene sospeso per abuso e poi cancellato, conserviamo soltanto l'impronta crittografica del numero WhatsApp e la data della sospensione, per impedire che lo stesso numero venga usato per una nuova iscrizione; il numero invece viene cancellato.";

    private const ANCHOR = 'senza codici OTP né copie dei testi ritirati.';

    public function up(): void
    {
        foreach (DB::table('pages')->where('slug', 'privacy')->get() as $page) {
            $body = (string) $page->body;
            // Senza la sezione community non c'è dove appoggiare la frase: la aggiunge 2026_09_18_110000.
            if (! str_contains($body, '## Community e verifica WhatsApp') || str_contains($body, self::RETENTION)) {
                continue;
            }
            // Subito dopo il paragrafo su export e cancellazione; se la redazione l'ha riscritto, in coda.
            $body = str_contains($body, self::ANCHOR)
                ? preg_replace('/'.preg_quote(self::ANCHOR, '/').'/', self::ANCHOR."\n\n".self::RETENTION, $body, 1) ?? $body
                : rtrim($body)."\n\n".self::RETENTION."\n";
            DB::table('pages')->where('id', $page->id)->update(['body' => $body, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // L'informativa resta: descrive dati che possono essere già stati trattenuti.
    }
};
