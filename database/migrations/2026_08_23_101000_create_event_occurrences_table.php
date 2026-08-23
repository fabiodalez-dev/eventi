<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('recurrence_id')->nullable()->constrained('event_recurrences')->nullOnDelete();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('effective_ends_at');
            $table->dateTime('doors_at')->nullable();
            $table->boolean('is_all_day')->default(false);
            $table->date('business_date');

            $table->enum('status', ['scheduled', 'cancelled', 'sold_out', 'postponed', 'moved'])->default('scheduled');
            $table->string('status_note')->nullable();
            $table->json('price_override')->nullable();
            $table->unsignedInteger('capacity_left')->nullable();
            $table->boolean('is_exception')->default(false);

            $table->datetimes();

            $table->index(['business_date', 'status']);
            $table->index('starts_at');
            $table->index('effective_ends_at');
            $table->index(['event_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_occurrences');
    }
};
