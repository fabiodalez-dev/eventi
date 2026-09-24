<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le coordinate approssimate accanto a quelle cifrate.
 *
 * `remembered_location` è cifrata e resta la fonte autorevole, con i suoi
 * tempi. Cifrata però non si può interrogare: per scegliere «chi è entro
 * quindici chilometri da questo locale» bisognerebbe decifrare riga per riga
 * ogni utente a ogni invio, che è esattamente il motivo per cui una notifica
 * serale «vicino a te» sembrava impraticabile.
 *
 * Queste due colonne contengono lo stesso valore già arrotondato a due
 * decimali che finisce dentro la colonna cifrata — circa un chilometro, non la
 * posizione esatta di nessuno — e nient'altro: niente data, niente storico.
 * Scadono insieme alla colonna cifrata e si cancellano con lei.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('location_lat', 5, 2)->nullable();
            $table->decimal('location_lng', 6, 2)->nullable();
            $table->index(['location_lat', 'location_lng'], 'users_coarse_location_index');
        });

        // Chi ha già una posizione salvata non deve riaccettarla: il valore
        // cifrato esiste, va solo ricopiato in chiaro nella forma arrotondata.
        User::withTrashed()->whereNotNull('remembered_location')
            ->where(fn ($query) => $query->whereNull('location_expires_at')->orWhere('location_expires_at', '>', now()))
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $position = $user->remembered_location;
                    if (! is_array($position) || ! isset($position['lat'], $position['lng'])) {
                        continue;
                    }
                    DB::table('users')->where('id', $user->id)->update([
                        'location_lat' => round((float) $position['lat'], 2),
                        'location_lng' => round((float) $position['lng'], 2),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_coarse_location_index');
            $table->dropColumn(['location_lat', 'location_lng']);
        });
    }
};
