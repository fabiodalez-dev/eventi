<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le coordinate interrogabili accanto a quelle cifrate.
 *
 * `remembered_location` è cifrata e resta la fonte autorevole, con i suoi
 * tempi. Cifrata però non si può interrogare: per scegliere «chi è entro
 * quindici chilometri da questo locale» bisognerebbe decifrare riga per riga
 * ogni utente a ogni invio, che è esattamente il motivo per cui una notifica
 * serale «vicino a te» sembrava impraticabile.
 *
 * Queste due colonne contengono lo stesso valore che finisce dentro la colonna
 * cifrata, e nient'altro: niente data, niente storico. Scadono insieme a lei e
 * si cancellano con lei.
 *
 * **Precise, per decisione del proprietario del 24/09/2026** (vedi
 * `docs/DECISIONS.md`), che ha sostituito l'arrotondamento a due decimali
 * deciso il 15/09. La stessa precisione dei locali — sette decimali — perché
 * le due cose si confrontano fra loro in ogni calcolo di distanza, e due
 * precisioni diverse nello stesso confronto sono un errore che non si vede.
 */
return new class extends Migration
{
    public function up(): void
    {
        $latitudeExists = Schema::hasColumn('users', 'location_lat');
        $longitudeExists = Schema::hasColumn('users', 'location_lng');
        Schema::table('users', function (Blueprint $table) use ($latitudeExists, $longitudeExists): void {
            if ($latitudeExists) {
                $table->decimal('location_lat', 10, 7)->nullable()->change();
            } else {
                $table->decimal('location_lat', 10, 7)->nullable();
            }
            if ($longitudeExists) {
                $table->decimal('location_lng', 10, 7)->nullable()->change();
            } else {
                $table->decimal('location_lng', 10, 7)->nullable();
            }
        });
        // Installations from the coarse-location rollout already have both columns.
        if (! Schema::hasIndex('users', 'users_location_index')) {
            Schema::table('users', function (Blueprint $table): void {
                if (Schema::hasIndex('users', 'users_coarse_location_index')) {
                    $table->renameIndex('users_coarse_location_index', 'users_location_index');
                } else {
                    $table->index(['location_lat', 'location_lng'], 'users_location_index');
                }
            });
        }

        /* Chi ha già una posizione salvata non deve riaccettarla: il valore
           cifrato esiste e va solo ricopiato in chiaro. Le posizioni salvate
           prima del 24/09/2026 restano arrotondate a due decimali — è ciò che
           era stato conservato, e non si può inventare la precisione che non è
           mai stata scritta. Si affinano da sole al primo aggiornamento. */
        User::withTrashed()->whereNotNull('remembered_location')
            ->where(fn ($query) => $query->whereNull('location_expires_at')->orWhere('location_expires_at', '>', now()))
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $position = $user->remembered_location;
                    if (! is_array($position) || ! isset($position['lat'], $position['lng'])) {
                        continue;
                    }
                    DB::table('users')->where('id', $user->id)->update([
                        'location_lat' => (float) $position['lat'],
                        'location_lng' => (float) $position['lng'],
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (DB::table('migrations')->where('migration', '2026_09_24_090000_add_coarse_location_to_users')->exists()) {
            // Those columns predate this upgrade. Keep their values on rollback.
            if (Schema::hasIndex('users', 'users_location_index') && ! Schema::hasIndex('users', 'users_coarse_location_index')) {
                Schema::table('users', fn (Blueprint $table) => $table->renameIndex('users_location_index', 'users_coarse_location_index'));
            }

            return;
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_location_index');
            $table->dropColumn(['location_lat', 'location_lng']);
        });
    }
};
