<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Porta nelle informative già installate il periodo di conservazione
 * dell'impronta trattenuta per gli account sospesi e cancellati (issue #104),
 * subito dopo la frase che quell'impronta la annuncia. Le installazioni nuove
 * lo ricevono da `community.privacy_notice`: la guardia su str_contains le
 * lascia intatte, e una seconda esecuzione non cambia nulla.
 */
return new class extends Migration
{
    private const SENTENCE = "L'impronta e la data della sospensione vengono eliminate automaticamente dopo ventiquattro mesi dalla sospensione.";

    private const ANCHOR = "Se un account viene sospeso per abuso e poi cancellato, conserviamo soltanto l'impronta crittografica del numero WhatsApp e la data della sospensione, per impedire che lo stesso numero venga usato per una nuova iscrizione; il numero invece viene cancellato.";

    public function up(): void
    {
        foreach (DB::table('pages')->where('slug', 'privacy')->get() as $page) {
            $original = (string) $page->body;
            // Senza la sezione community non c'è dove appoggiare la frase: la aggiunge 2026_09_18_110000.
            if (! str_contains($original, '## Community e verifica WhatsApp') || str_contains($original, self::SENTENCE)) {
                continue;
            }
            // Nello stesso paragrafo della frase sull'impronta; se la redazione l'ha riscritta, in coda.
            $body = str_contains($original, self::ANCHOR)
                ? preg_replace('/'.preg_quote(self::ANCHOR, '/').'/', self::ANCHOR.' '.self::SENTENCE, $original, 1) ?? $original
                : rtrim($original)."\n\n".self::SENTENCE."\n";
            if ($body !== $original) {
                DB::table('pages')->where('id', $page->id)->update(['body' => $body, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // L'informativa resta: descrive un periodo di conservazione già in vigore.
    }
};
