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
        foreach (['scheduled_notifications', 'notification_log'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->enum('channel', ['mail', 'push', 'database', 'both'])->default('mail')->change());
        }
    }

    public function down(): void
    {
        foreach (['scheduled_notifications', 'notification_log'] as $table) {
            DB::table($table)->where('channel', 'both')->update(['channel' => 'mail']);
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->enum('channel', ['mail', 'push', 'database'])->default('mail')->change());
        }
    }
};
