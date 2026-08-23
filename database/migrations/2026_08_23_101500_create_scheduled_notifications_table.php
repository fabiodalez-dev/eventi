<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('notifiable_type', 64)->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->string('type', 64);
            $table->enum('channel', ['mail', 'push', 'database'])->default('mail');
            $table->dateTime('send_at');
            $table->dateTime('sent_at')->nullable();
            $table->enum('status', ['pending', 'sent', 'skipped', 'failed', 'cancelled'])->default('pending');
            $table->string('dedupe_key', 191)->unique();
            $table->json('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->datetimes();

            $table->index(['status', 'send_at']);
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_notifications');
    }
};
