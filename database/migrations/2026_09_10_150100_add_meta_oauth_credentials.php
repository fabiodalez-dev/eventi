<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table): void {
            $table->string('app_id')->nullable();
            $table->text('app_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', fn (Blueprint $table) => $table->dropColumn(['app_id', 'app_secret']));
    }
};
