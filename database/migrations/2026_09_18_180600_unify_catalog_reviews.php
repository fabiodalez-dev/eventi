<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una ripresa dopo un'esecuzione già completata non deve tentare di rinominare di nuovo.
        if (Schema::hasTable('reviews') && ! Schema::hasTable('venue_reviews')) {
            return;
        }
        Schema::table('venue_reviews', function (Blueprint $table): void {
            $table->dropForeign(['venue_id']);
            $table->dropUnique(['venue_id', 'user_id']);
            $table->dropIndex(['venue_id', 'status', 'created_at']);
            $table->renameColumn('venue_id', 'reviewable_id');
            $table->renameColumn('body', 'review');
        });
        Schema::rename('venue_reviews', 'reviews');
        Schema::table('reviews', function (Blueprint $table): void {
            $table->string('reviewable_type', 40)->default('venue');
            $table->string('department')->default('default');
            $table->boolean('recommend')->default(false);
            $table->boolean('approved')->default(false);
            $table->text('review')->nullable()->change();
            $table->unique(['reviewable_type', 'reviewable_id', 'user_id']);
            $table->index(['reviewable_type', 'reviewable_id', 'approved']);
        });
        Schema::create('ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained('reviews')->cascadeOnDelete();
            $table->string('key', 40);
            $table->unsignedTinyInteger('value');
            $table->timestamps();
            $table->unique(['review_id', 'key']);
        });
        DB::table('reviews')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('ratings')->insert(['review_id' => $row->id, 'key' => 'overall', 'value' => $row->rating, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
            }
        });
        DB::table('reviews')->where('status', 'approved')->update(['approved' => true]);
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropColumn('rating');
        });
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
