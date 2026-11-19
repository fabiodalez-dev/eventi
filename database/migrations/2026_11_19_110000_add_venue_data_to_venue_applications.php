<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La registrazione di un locale chiede più dati e nasce con un account.
 *
 * - `venue_data`: i dati pubblici della scheda (sito, email e telefono del
 *   locale, CAP, comune, capienza, presentazione, social), separati dai
 *   contatti di chi scrive, che restano privati;
 * - `image_path`: l'immagine facoltativa, sul disco privato finché la
 *   redazione non approva;
 * - `email_confirmed_at`: quando la richiesta è entrata in coda. Vuoto finché
 *   chi l'ha mandata non conferma l'email: lo stato resta `pending` e l'ENUM
 *   non cambia (un valore nuovo in un ENUM MySQL vuol dire riscrivere la
 *   tabella e toccare ogni lettura dello stato).
 *
 * Le richieste già presenti erano in coda: prendono la data di creazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_applications', function (Blueprint $table): void {
            $table->json('venue_data')->nullable()->after('socials');
            $table->string('image_path')->nullable()->after('venue_data');
            $table->dateTime('email_confirmed_at')->nullable()->after('status');
            $table->index(['status', 'email_confirmed_at']);
        });

        DB::table('venue_applications')->whereNull('email_confirmed_at')->update(['email_confirmed_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('venue_applications', function (Blueprint $table): void {
            $table->dropIndex(['status', 'email_confirmed_at']);
            $table->dropColumn(['venue_data', 'image_path', 'email_confirmed_at']);
        });
    }
};
