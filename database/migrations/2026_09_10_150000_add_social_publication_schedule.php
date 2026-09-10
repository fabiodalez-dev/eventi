<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_publications', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable();
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('social_publications', function (Blueprint $table): void {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn('scheduled_at');
        });
    }
};
