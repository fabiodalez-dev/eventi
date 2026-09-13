<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_note')->nullable();
            $table->timestamps();
            $table->unique(['venue_id', 'user_id']);
            $table->index(['venue_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_reviews');
    }
};
