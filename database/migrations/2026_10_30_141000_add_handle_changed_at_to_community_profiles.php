<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Quando il nome utente è stato cambiato l'ultima volta (D64, rinvio chiuso).
 *
 * Le righe esistenti restano a null, che vuol dire «nessuna attesa»: chi ha
 * già un profilo non si ritrova bloccato per un cambio fatto prima che la
 * regola esistesse. Il primo nome scelto non scrive la colonna, perché non è
 * un cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_profiles', function (Blueprint $table): void {
            $table->timestamp('handle_changed_at')->nullable()->after('handle');
        });
    }

    public function down(): void
    {
        Schema::table('community_profiles', function (Blueprint $table): void {
            $table->dropColumn('handle_changed_at');
        });
    }
};
