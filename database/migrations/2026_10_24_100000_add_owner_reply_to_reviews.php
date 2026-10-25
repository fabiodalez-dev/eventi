<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La risposta pubblica del locale o dell'organizzatore a una recensione.
 *
 * Una sola per recensione, scritta dal pannello da chi gestisce il soggetto
 * recensito; si vede sotto la recensione finché questa è pubblicata. Chi l'ha
 * scritta resta nella riga, perché la firma pubblica è il nome del locale ma
 * la responsabilità è di una persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->text('owner_reply')->nullable();
            $table->foreignId('owner_reply_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('owner_replied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_reply_by');
            $table->dropColumn(['owner_reply', 'owner_replied_at']);
        });
    }
};
