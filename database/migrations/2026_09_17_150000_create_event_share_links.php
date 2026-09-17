<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_share_links', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 7)->collation('ascii_bin')->unique();
            $table->string('target_key', 80)->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->nullable()->constrained('event_occurrences')->cascadeOnDelete();
            $table->string('channel', 12);
            $table->timestamps();
        });
        Schema::create('event_share_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('share_link_id')->constrained('event_share_links')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unique(['share_link_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_share_daily');
        Schema::dropIfExists('event_share_links');
    }
};
