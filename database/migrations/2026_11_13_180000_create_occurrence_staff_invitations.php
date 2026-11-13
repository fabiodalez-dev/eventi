<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gli inviti al personale di ingresso ancora senza risposta (D67).
 *
 * Chi gestisce una data scrive un indirizzo; finché la persona non accetta
 * dal collegamento della mail, la data conosce solo quell'indirizzo, che sia
 * registrato o no. All'accettazione la riga sparisce e la persona entra in
 * `occurrence_checkin_staff`, come prima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occurrence_staff_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->string('email');
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at');
            $table->timestamps();
            $table->unique(['occurrence_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occurrence_staff_invitations');
    }
};
