<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['events', 'venues', 'categories', 'tags', 'cities', 'pages'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->json('content_details')->nullable();
                $blueprint->boolean('is_demo')->default(false);
                if ($table !== 'events') {
                    $blueprint->json('seo')->nullable();
                }
            });
        }
        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->dateTime('previous_starts_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['events', 'venues', 'categories', 'tags', 'cities', 'pages'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn(
                $table === 'events' ? ['content_details', 'is_demo'] : ['content_details', 'is_demo', 'seo']));
        }
        Schema::table('event_occurrences', fn (Blueprint $table) => $table->dropColumn('previous_starts_at'));
    }
};
