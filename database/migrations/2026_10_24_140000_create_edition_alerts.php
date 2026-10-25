<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Avvisami alla prossima edizione», chiesto sulla scheda di un evento senza
 * più date in programma. Un avviso di una volta: la riga sparisce quando parte
 * (`App\Services\Events\EditionAlerts`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edition_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'event_id']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edition_alerts');
    }
};
