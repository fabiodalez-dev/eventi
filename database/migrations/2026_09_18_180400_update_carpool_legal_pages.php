<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sections = [
            'privacy' => file_get_contents(resource_path('legal/carpool/privacy-2026-09-18.it.md')),
            'termini' => "## Passaggi gratuiti tra utenti\n\nIl car pooling è riservato agli account personali con email e WhatsApp verificati e dichiarazione di maggiore età. Il passaggio è gratuito e richiede l’accettazione del conducente. Prima di offrire o richiedere un passaggio è necessario accettare le [regole specifiche dei passaggi](/passaggi/regole), che integrano questi termini. I gestori dei locali non accedono alle conversazioni private o all’amministrazione del car pooling.\n",
        ];
        foreach ($sections as $slug => $section) {
            foreach (DB::table('pages')->where('slug', $slug)->get() as $page) {
                $heading = strtok($section, "\n");
                if (! str_contains($page->body, $heading)) {
                    DB::table('pages')->where('id', $page->id)->update(['body' => $page->body."\n\n".$section, 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Retain disclosures for processing already performed, including on rollback.
    }
};
