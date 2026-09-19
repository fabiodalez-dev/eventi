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
        $table = Schema::hasTable('venue_reviews') ? 'venue_reviews' : 'reviews';
        // Rinominare la tabella non rinomina indici e vincoli: i nomi restano
        // quelli nati con `venue_reviews`, prima e dopo il cambio di nome.
        if (in_array('venue_reviews_venue_id_foreign', array_column(Schema::getForeignKeys($table), 'name'), true)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign('venue_reviews_venue_id_foreign'));
        }
        if (Schema::hasIndex($table, 'venue_reviews_venue_id_user_id_unique')) {
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique('venue_reviews_venue_id_user_id_unique'));
        }
        if (Schema::hasIndex($table, 'venue_reviews_venue_id_status_created_at_index')) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex('venue_reviews_venue_id_status_created_at_index'));
        }
        if (Schema::hasColumn($table, 'venue_id')) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('venue_id', 'reviewable_id'));
        }
        if (Schema::hasColumn($table, 'body')) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('body', 'review'));
        }
        if ($table === 'venue_reviews') {
            Schema::rename('venue_reviews', 'reviews');
        }
        foreach (['reviewable_type' => fn (Blueprint $t) => $t->string('reviewable_type', 40)->default('venue'),
            'department' => fn (Blueprint $t) => $t->string('department')->default('default'),
            'recommend' => fn (Blueprint $t) => $t->boolean('recommend')->default(false),
            'approved' => fn (Blueprint $t) => $t->boolean('approved')->default(false)] as $column => $definition) {
            if (! Schema::hasColumn('reviews', $column)) {
                Schema::table('reviews', $definition);
            }
        }
        Schema::table('reviews', fn (Blueprint $t) => $t->text('review')->nullable()->change());
        if (! Schema::hasIndex('reviews', ['reviewable_type', 'reviewable_id', 'user_id'], 'unique')) {
            Schema::table('reviews', fn (Blueprint $t) => $t->unique(['reviewable_type', 'reviewable_id', 'user_id']));
        }
        if (! Schema::hasIndex('reviews', ['reviewable_type', 'reviewable_id', 'approved'])) {
            Schema::table('reviews', fn (Blueprint $t) => $t->index(['reviewable_type', 'reviewable_id', 'approved']));
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
            Schema::table('reviews', fn (Blueprint $t) => $t->dropColumn('rating'));
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
