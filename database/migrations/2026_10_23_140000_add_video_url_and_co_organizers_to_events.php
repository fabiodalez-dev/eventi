<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Due cose che una scheda evento non sapeva dire.
 *
 * **Il video.** Un trailer, il teaser di un concerto, la clip dello
 * spettacolo: `video_url` tiene l'indirizzo originale (YouTube o Vimeo) e la
 * pagina lo mostra come riquadro da aprire con un gesto, senza caricare
 * niente dal fornitore finché la persona non sceglie di guardarlo.
 *
 * **I co-organizzatori.** `events.organizer_id` resta l'organizzatore
 * principale, quello che appare per primo e risponde dell'evento; la tabella
 * ponte aggiunge gli altri. Una rassegna fatta da due associazioni è di
 * tutte e due, e tutte e due la vedono nel proprio pannello.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('video_url', 2048)->nullable()->after('gallery');
        });

        Schema::create('event_organizer', function (Blueprint $table): void {
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->cascadeOnDelete();
            $table->primary(['event_id', 'organizer_id']);
            $table->index('organizer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_organizer');

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('video_url');
        });
    }
};
