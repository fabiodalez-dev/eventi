<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $notice = "Con la scelta «Usa e ricorda la mia posizione» conserviamo solo l'ultima posizione approssimata (coordinate arrotondate a due decimali, circa un chilometro), cifrata nel cookie `incitta_location` o nella memoria dell'app e, se accedi, nel tuo account. Nessuna cronologia e nessun uso pubblicitario. La posizione scade sei mesi dopo l'ultimo aggiornamento. Con il permesso già concesso e la localizzazione disponibile si aggiorna quando torni alla funzione; altrimenti usiamo la posizione ricordata o il centro città. Puoi cancellarla e interrompere gli aggiornamenti con «Cancella la posizione ricordata». Le copie sugli altri dispositivi si cancellano da ciascun dispositivo. La normale ricerca per distanza senza questa scelta non salva coordinate nell'account.";
        foreach (DB::table('pages')->whereIn('slug', ['privacy', 'cookie'])->get() as $page) {
            $body = $page->body;
            if ($page->slug === 'privacy') {
                $body = preg_replace('/La posizione te la chiede il browser.*?spariscono con esso\./s', $notice, $body);
            }
            if (! str_contains($body, 'incitta_location')) {
                $body .= "\n\n## Posizione ricordata\n\n".$notice;
            }
            DB::table('pages')->where('id', $page->id)->update(['body' => $body, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Retain the updated privacy disclosure, including on application rollback.
    }
};
