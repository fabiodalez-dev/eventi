<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->json('reminder_sent_at')->nullable();
            $table->datetimes();

            $table->unique(['user_id', 'occurrence_id']);
            $table->index('occurrence_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_events');
    }
};
