<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('pages')->where('slug', 'privacy')->get() as $page) {
            $body = str_replace('sesso, numero di telefono, indirizzo di casa', 'sesso, indirizzo di casa', $page->body);
            $body = preg_replace('/Con nessuno, a parte i fornitori tecnici.*?non possono usare i dati per proprio conto\./s', __('community.privacy_providers', [], 'it'), $body) ?? $body;
            $body = preg_replace('/Non\s+c\x{2019}è un trasferimento di dati fuori dall\x{2019}Unione europea nella configurazione attuale del\s+servizio\./u', '', $body) ?? $body;
            $body = preg_replace("/Non\\s+c'è un trasferimento di dati fuori dall'Unione europea nella configurazione attuale del\\s+servizio\\./", '', $body) ?? $body;
            if (! str_contains($body, '## Community e verifica WhatsApp')) {
                $body .= "\n\n".__('community.privacy_notice', [], 'it');
            }
            DB::table('pages')->where('id', $page->id)->update(['body' => $body, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Keep the disclosure for data already processed, including during rollback.
    }
};
