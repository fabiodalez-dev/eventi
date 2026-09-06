<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->json('graphic_options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', fn (Blueprint $table) => $table->dropColumn('graphic_options'));
    }
};
