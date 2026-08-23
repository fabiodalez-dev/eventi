<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 64);
            $table->enum('channel', ['mail', 'push', 'database'])->default('mail');
            $table->dateTime('sent_at');
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('clicked_at')->nullable();
            $table->datetimes();

            $table->index(['user_id', 'sent_at']);
            $table->index(['type', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
    }
};
