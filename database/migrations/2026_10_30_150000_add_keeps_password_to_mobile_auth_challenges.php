<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Il collegamento dell'app nato da una registrazione (o da un accesso con la
 * password giusta su un account ancora in sospeso) è legato all'installazione
 * che ha scelto o dimostrato la password: chi lo scambia con il verificatore
 * PKCE possiede la casella **ed** è chi conosce la password, quindi la
 * password resta valida (`ClaimMailbox::confermaRegistrazione()`). Gli altri
 * collegamenti restano come prima: la prima prova della casella azzera le
 * credenziali nate prima di essa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_auth_challenges', function (Blueprint $table): void {
            $table->boolean('keeps_password')->default(false)->after('password_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_auth_challenges', fn (Blueprint $table) => $table->dropColumn('keeps_password'));
    }
};
