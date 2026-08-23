<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('platform', ['ios', 'android', 'web'])->default('web');
            $table->string('push_token', 512)->nullable();
            $table->string('endpoint', 512)->nullable();
            $table->json('keys')->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('locale', 5)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->datetimes();

            $table->index(['user_id', 'revoked_at']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
