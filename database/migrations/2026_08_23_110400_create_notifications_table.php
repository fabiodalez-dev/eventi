<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'archivio in-app delle notifiche (§15.6): la tabella del canale `database`
 * di Laravel, che D8 ha promosso da comodità a canale di destinazione insieme
 * all'email dopo l'esclusione del Web Push.
 *
 * È la migration del framework, con le sole due differenze che valgono per
 * tutto questo schema: date `DATETIME` invece di `TIMESTAMP` (deviazione 11 di
 * `SCHEMA.md`) e colonna morph di lunghezza dichiarata, perché `notifiable_type`
 * contiene l'alias `user` della morph map e non il nome di una classe PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type', 64);
            $table->unsignedBigInteger('notifiable_id');
            $table->json('data');
            $table->dateTime('read_at')->nullable();
            $table->datetimes();

            $table->index(['notifiable_type', 'notifiable_id'], 'notifications_notifiable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
