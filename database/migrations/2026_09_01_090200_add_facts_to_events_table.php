<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La scheda tecnica dell'evento: righe `{label, value}` — apertura porte,
 * durata, età minima, cosa serve portare.
 *
 * È dell'evento e non del locale perché cambia con la serata: la stessa sala
 * apre alle 19:30 per il concerto e alle 20:45 per lo spettacolo. Quello che
 * invece appartiene al luogo sta in `venues.info`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->json('facts')->nullable()->after('external_links');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('facts');
        });
    }
};
