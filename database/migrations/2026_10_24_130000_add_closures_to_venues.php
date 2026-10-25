<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chiusure straordinarie e ferie del locale (`App\Support\VenueClosures`):
 * giorni di calendario che valgono sopra gli orari settimanali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->json('closures')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->dropColumn('closures');
        });
    }
};
