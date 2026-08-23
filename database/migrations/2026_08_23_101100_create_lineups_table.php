<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lineups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->string('name');
            $table->enum('role', ['live', 'dj', 'opening', 'special_guest', 'speaker'])->default('live');
            $table->dateTime('starts_at')->nullable();
            $table->string('url')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->datetimes();

            $table->index(['occurrence_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lineups');
    }
};
