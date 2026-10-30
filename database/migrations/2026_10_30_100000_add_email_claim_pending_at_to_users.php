<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Una registrazione fatta da sé non prova di possedere la casella: chiunque
 * può iscriversi con l'indirizzo di un altro e scegliere la password. Questa
 * data dice che le credenziali scelte alla registrazione aspettano ancora la
 * prova di chi le ha scelte (`ClaimMailbox`). Le righe esistenti restano
 * vuote: per loro non si sa, e gli account non verificati sono comunque
 * coperti da `email_verified_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('email_claim_pending_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_claim_pending_at'));
    }
};
