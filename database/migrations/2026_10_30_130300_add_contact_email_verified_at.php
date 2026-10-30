<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Doppio opt-in del recapito del modulo contatti (D64, rinvio chiuso).
 *
 * Il recapito lo scrive chi gestisce la scheda e fin qui nessuno lo
 * confermava: da ora il modulo pubblico si accende solo con
 * `contact_email_verified_at` valorizzato.
 *
 * **Backfill.** I recapiti già salvati diventano confermati al deploy: sono
 * stati scritti da gestori e redazione quando la conferma non esisteva, e
 * spegnere di colpo moduli che ricevono messaggi veri farebbe più danno del
 * rischio che resta (un recapito sbagliato è già sotto i tetti giornalieri di
 * `PublicContactService`). Il prossimo cambio d'indirizzo passa dalla
 * conferma come tutti.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['venues', 'organizers'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->timestamp('contact_email_verified_at')->nullable()->after('contact_email');
            });

            /* Solo i moduli accesi: un recapito salvato con il modulo spento
               non ha mai ricevuto niente, e alla prima accensione passa dalla
               conferma come uno nuovo. */
            DB::table($name)->whereNotNull('contact_email')->where('contact_email', '!=', '')
                ->where('contact_mode', '!=', 'disabled')
                ->update(['contact_email_verified_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['venues', 'organizers'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('contact_email_verified_at'));
        }
    }
};
