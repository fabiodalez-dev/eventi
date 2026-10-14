<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Un'abilitazione appartiene a un locale oppure a un organizzatore: uno dei
 * due, mai entrambi. Il vincolo «esattamente uno» lo applica il modello,
 * perche' MariaDB accetta i CHECK ma Laravel non li descrive nello schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsorship_grants', function (Blueprint $table): void {
            $table->foreignId('venue_id')->nullable()->change();
            $table->foreignId('organizer_id')->nullable()->after('venue_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sponsorship_grants', function (Blueprint $table): void {
            $table->dropForeign(['organizer_id']);
            $table->dropColumn('organizer_id');
            $table->foreignId('venue_id')->nullable(false)->change();
        });
    }
};
