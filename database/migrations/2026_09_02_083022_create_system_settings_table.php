<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le impostazioni di sistema tipizzate (`spatie/laravel-settings`).
 *
 * **Non si chiama `settings`, e non e' un capriccio.** Quella tabella esiste
 * gia' in questo progetto e fa un altro mestiere: chiave/valore tipizzato per
 * i feature flag, come previsto dal piano. Qui dentro vivono invece i gruppi
 * con proprieta' dichiarate e cifratura a riposo — la configurazione della
 * posta e' il primo. Due formati nella stessa tabella sarebbero un guaio che
 * si scopre tardi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->tabella(), function (Blueprint $table): void {
            $table->id();

            $table->string('group');
            $table->string('name');
            /* Il pacchetto lo usa per impedire la modifica da pannello di una
               proprieta' che deve restare come sta. */
            $table->boolean('locked')->default(false);
            $table->json('payload');

            $table->timestamps();

            $table->unique(['group', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tabella());
    }

    private function tabella(): string
    {
        $nome = config('settings.repositories.database.table');

        return is_string($nome) && $nome !== '' ? $nome : 'system_settings';
    }
};
