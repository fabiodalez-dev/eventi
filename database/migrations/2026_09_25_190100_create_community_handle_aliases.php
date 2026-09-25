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
        Schema::create('community_handle_aliases', function (Blueprint $table): void {
            $table->string('handle', 40)->primary();
            $table->foreignId('community_profile_id')->constrained('community_profiles')->cascadeOnDelete();
        });
        DB::table('community_profiles')->orderBy('id')->chunkById(500, function ($profiles): void {
            foreach ($profiles as $profile) {
                DB::table('community_handle_aliases')->insert(['handle' => $profile->handle, 'community_profile_id' => $profile->id]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_handle_aliases');
    }
};
