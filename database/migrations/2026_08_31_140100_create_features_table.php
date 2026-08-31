<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Migrations\PennantMigration;

/**
 * Il magazzino dei valori risolti di `laravel/pennant`, pubblicato come lo
 * genera il pacchetto (D13).
 *
 * La riga qui dentro è una **cache**, non la verità: la verità di ogni
 * interruttore è la funzione dichiarata in `App\Support\Features`. Chi vuole
 * spegnere una funzione per una città non scrive qui, cambia
 * `cities.settings` — e `pennant:purge` butta via ciò che è stato risolto
 * prima.
 */
return new class extends PennantMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->timestamps();

            $table->unique(['name', 'scope']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
