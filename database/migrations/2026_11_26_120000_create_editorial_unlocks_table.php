<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lo sblocco della redazione nell'app, per token (decisioni 2 e 3 del piano
 * della redazione): una riga per token dell'app, scritta da
 * `POST /api/v1/editorial/unlock` con la password dell'account.
 *
 * - `unlocked_at`: quando la persona ha scritto la password l'ultima volta.
 *   `RequireEditorialUnlock` rifiuta le rotte della redazione se è più
 *   vecchio di 12 ore.
 * - `confirmed_until`: fino a quando valgono le azioni pericolose senza
 *   chiedere di nuovo la password (15 minuti dopo lo sblocco),
 *   `RequireRecentPassword`.
 *
 * La riga sparisce con il token (`cascadeOnDelete`): uscire dall'app, o un
 * token revocato dal sito, chiude anche la redazione. Solo colonne e chiavi
 * semplici, valide uguali su MySQL 8 e MariaDB 10.11.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_unlocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('token_id')->unique()->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('unlocked_at');
            $table->timestamp('confirmed_until');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_unlocks');
    }
};
