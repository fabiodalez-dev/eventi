<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quanto traffico ha ogni spazio, ora per ora: le occasioni viste (vendute o
 * no), quelle in cui c'era una sponsorizzazione, e i clic.
 *
 * Serve a due cose: prevedere quante visualizzazioni uno spazio può dare in un
 * periodo, e il tasso di clic medio di una collocazione. Sono solo contatori:
 * nessun dato sulla persona, nessun indirizzo, nessun utente.
 *
 * Giorno e ora sono nel fuso della città, come in `sponsorship_daily_stats`.
 * Si pota dopo 70 giorni (`sponsorship.retention.hourly_days`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_slot_hourly', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('placement', 32);
            $table->string('channel', 16);
            $table->date('day');
            $table->unsignedTinyInteger('hour');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('sponsored_views')->default(0);
            $table->unsignedInteger('clicks')->default(0);

            $table->unique(['city_id', 'placement', 'channel', 'day', 'hour'], 'sponsorship_slot_hourly_unique');
            /* La potatura cerca per giorno, che nella chiave unica non è in testa. */
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsorship_slot_hourly');
    }
};
