<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('pages')->where('slug', 'chi-siamo')->get() as $page) {
            $body = preg_replace('/\*\*Non trattare chi legge come un dato\.\*\* Nessuna pubblicità, nessuna profilazione,\s+nessun cookie di terze parti, nessuna posizione conservata\./u', '**Lasciare il controllo a chi legge.** Sponsorizzazioni riconoscibili e preferenze per i consensi. La posizione viene ricordata solo su scelta esplicita, per sei mesi e senza cronologia degli spostamenti.', $page->body);
            if ($body !== null && $body !== $page->body) {
                DB::table('pages')->where('id', $page->id)->update(['body' => $body, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Keep accurate disclosures during application rollback.
    }
};
