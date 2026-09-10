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
        Schema::table('events', function (Blueprint $table): void {
            $table->unsignedInteger('next_occurrence_number')->default(1);
        });
        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->unsignedInteger('url_number')->nullable();
            $table->unique(['event_id', 'url_number']);
        });

        // Include anche le date eliminate: un numero non deve mai essere riutilizzato.
        DB::table('events')->orderBy('id')->chunkById(200, function ($events): void {
            foreach ($events as $event) {
                $number = 1;
                foreach (DB::table('event_occurrences')->where('event_id', $event->id)
                    ->orderBy('starts_at')->orderBy('id')->pluck('id') as $id) {
                    DB::table('event_occurrences')->where('id', $id)->update(['url_number' => $number++]);
                }
                DB::table('events')->where('id', $event->id)->update(['next_occurrence_number' => $number]);
            }
        });

        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->unsignedInteger('url_number')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->dropUnique(['event_id', 'url_number']);
            $table->dropColumn('url_number');
        });
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('next_occurrence_number');
        });
    }
};
