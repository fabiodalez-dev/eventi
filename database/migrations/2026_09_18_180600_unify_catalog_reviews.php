<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Su MariaDB ogni istruzione DDL è confermata da sola: un errore a metà
     * lascia lo schema in uno stato intermedio. Per questo ogni passo verifica
     * da sé se è già stato fatto, e una ripresa completa quelli mancanti invece
     * di fermarsi al primo segno di un'esecuzione precedente.
     */
    public function up(): void
    {
        // Il cambio di nome della tabella è l'ultimo dei passi su `venue_reviews`:
        // se la tabella ha ancora il vecchio nome, vincoli, indici e colonne
        // possono essere in qualunque punto e ognuno si controlla da sé. Rinominare
        // la tabella non rinomina indici e vincoli, che restano `venue_reviews_*`.
        if (Schema::hasTable('venue_reviews')) {
            if (in_array('venue_reviews_venue_id_foreign', array_column(Schema::getForeignKeys('venue_reviews'), 'name'), true)) {
                Schema::table('venue_reviews', function (Blueprint $table): void {
                    $table->dropForeign(['venue_id']);
                });
            }
            if (Schema::hasIndex('venue_reviews', 'venue_reviews_venue_id_user_id_unique')) {
                Schema::table('venue_reviews', function (Blueprint $table): void {
                    $table->dropUnique(['venue_id', 'user_id']);
                });
            }
            if (Schema::hasIndex('venue_reviews', 'venue_reviews_venue_id_status_created_at_index')) {
                Schema::table('venue_reviews', function (Blueprint $table): void {
                    $table->dropIndex(['venue_id', 'status', 'created_at']);
                });
            }
            if (Schema::hasColumn('venue_reviews', 'venue_id')) {
                Schema::table('venue_reviews', function (Blueprint $table): void {
                    $table->renameColumn('venue_id', 'reviewable_id');
                });
            }
            if (Schema::hasColumn('venue_reviews', 'body')) {
                Schema::table('venue_reviews', function (Blueprint $table): void {
                    $table->renameColumn('body', 'review');
                });
            }
            Schema::rename('venue_reviews', 'reviews');
        }
        if (! Schema::hasColumn('reviews', 'reviewable_type')) {
            Schema::table('reviews', function (Blueprint $table): void {
                $table->string('reviewable_type', 40)->default('venue');
            });
        }
        if (! Schema::hasColumn('reviews', 'department')) {
            Schema::table('reviews', function (Blueprint $table): void {
                $table->string('department')->default('default');
            });
        }
        if (! Schema::hasColumn('reviews', 'recommend')) {
            Schema::table('reviews', function (Blueprint $table): void {
                $table->boolean('recommend')->default(false);
            });
        }
        if (! Schema::hasColumn('reviews', 'approved')) {
            Schema::table('reviews', function (Blueprint $table): void {
                $table->boolean('approved')->default(false);
            });
        }
        Schema::table('reviews', function (Blueprint $table): void {
            $table->text('review')->nullable()->change();
        });
        if (! Schema::hasIndex('reviews', ['reviewable_type', 'reviewable_id', 'user_id'], 'unique')) {
            Schema::table('reviews', function (Blueprint $table): void {
                $table->unique(['reviewable_type', 'reviewable_id', 'user_id']);
            });
        }
        if (! Schema::hasIndex('reviews', ['reviewable_type', 'reviewable_id', 'approved'])) {
            Schema::table('reviews', function (Blueprint $table): void {
                $table->index(['reviewable_type', 'reviewable_id', 'approved']);
            });
        }
        if (! Schema::hasTable('ratings')) {
            Schema::create('ratings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
                $table->string('key', 40);
                $table->unsignedTinyInteger('value');
                $table->timestamps();
                $table->unique(['review_id', 'key']);
            });
        }
        // Il voto vive in `reviews.rating` finché l'ultimo passo non lo toglie:
        // fino ad allora copia e allineamento si possono ripetere senza danni.
        if (Schema::hasColumn('reviews', 'rating')) {
            DB::table('reviews')->orderBy('id')->chunkById(200, function ($rows): void {
                DB::table('ratings')->insertOrIgnore($rows->map(fn ($row): array => ['review_id' => $row->id, 'key' => 'overall', 'value' => $row->rating,
                    'created_at' => $row->created_at, 'updated_at' => $row->updated_at])->all());
            });
            DB::table('reviews')->where('status', 'approved')->update(['approved' => true]);
            Schema::table('reviews', function (Blueprint $table): void {
                $table->dropColumn('rating');
            });
        }
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->unsignedTinyInteger('rating')->default(1);
        });
        DB::statement("UPDATE reviews r SET r.rating = COALESCE((SELECT value FROM ratings WHERE review_id = r.id AND `key` = 'overall'), 1)");
        DB::table('reviews')->where('reviewable_type', '!=', 'venue')->delete();
        DB::table('reviews')->whereNull('review')->update(['review' => '']);
        Schema::dropIfExists('ratings');
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropUnique(['reviewable_type', 'reviewable_id', 'user_id']);
            $table->dropIndex(['reviewable_type', 'reviewable_id', 'approved']);
            $table->dropColumn(['reviewable_type', 'department', 'recommend', 'approved']);
            $table->renameColumn('reviewable_id', 'venue_id');
            $table->renameColumn('review', 'body');
        });
        Schema::rename('reviews', 'venue_reviews');
        Schema::table('venue_reviews', function (Blueprint $table): void {
            $table->text('body')->nullable(false)->change();
            $table->foreign('venue_id')->references('id')->on('venues')->cascadeOnDelete();
            $table->unique(['venue_id', 'user_id']);
            $table->index(['venue_id', 'status', 'created_at']);
        });
    }
};
