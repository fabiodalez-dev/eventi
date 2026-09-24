<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I link brevi imparano due cose: puntare a un locale e non solo a un evento,
 * e portare un canale scelto da chi condivide.
 *
 * `channel` passava da dodici caratteri, misura tagliata sui quattro nomi di
 * sistema; un'etichetta libera normalizzata ci sta dentro solo per caso.
 * `event_id` diventa facoltativa perche' un link del locale non ha un evento,
 * e `target_key` resta l'unica chiave di unicita': e' quella che rende la
 * creazione idempotente, e le righe gia' scritte non la cambiano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_share_links', function (Blueprint $table): void {
            $table->string('channel', 40)->change();
            $table->unsignedBigInteger('event_id')->nullable()->change();
            $table->foreignId('venue_id')->nullable()->after('event_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_share_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('venue_id');
            $table->string('channel', 12)->change();
            $table->unsignedBigInteger('event_id')->nullable(false)->change();
        });
    }
};
