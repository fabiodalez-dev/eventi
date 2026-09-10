<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table): void {
            $table->text('telegram_bot_token')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->boolean('telegram_enabled')->default(false);
            $table->timestamp('telegram_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', fn (Blueprint $table) => $table->dropColumn(['telegram_bot_token', 'telegram_chat_id', 'telegram_enabled', 'telegram_verified_at']));
    }
};
