<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            /* Il nome è facoltativo (§15.2): di una persona si raccolgono email
               e password, il resto lo dà se vuole. */
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->dateTime('email_verified_at')->nullable();
            $table->string('password');
            $table->string('timezone', 64)->default('Europe/Rome');
            $table->string('locale', 5)->default('it');
            $table->json('notification_preferences')->nullable();
            $table->time('daily_digest_time')->nullable();
            $table->json('quiet_hours')->nullable();
            $table->dateTime('marketing_opt_in_at')->nullable();
            $table->dateTime('last_active_at')->nullable();
            $table->rememberToken();
            $table->datetimes();
            $table->softDeletesDatetime();

            $table->index('last_active_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
