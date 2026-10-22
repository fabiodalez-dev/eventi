<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le raccolte: «la mia lista per il weekend», «cose da fare con gli ospiti».
 *
 * I salvataggi sono piatti e privati; una raccolta è un elenco con un nome,
 * fatto di singole date, che si può condividere con un link. Il link è un
 * gettone casuale e non un numero: chi lo riceve vede quella raccolta e
 * nient'altro, e non può indovinarne un'altra. Privata finché la persona non
 * la rende pubblica, e si richiude in qualunque momento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('token', 24)->unique();
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'city_id']);
        });

        Schema::create('event_collection_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->constrained('event_collections')->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->string('note', 200)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['collection_id', 'occurrence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_collection_items');
        Schema::dropIfExists('event_collections');
    }
};
