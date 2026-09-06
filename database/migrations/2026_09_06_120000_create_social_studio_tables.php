<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Meta');
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->text('access_token')->nullable();
            $table->string('page_id')->nullable();
            $table->string('instagram_id')->nullable();
            $table->string('graph_version')->default('v25.0');
            $table->boolean('facebook_enabled')->default(false);
            $table->boolean('instagram_enabled')->default(false);
            $table->boolean('automatic')->default(false);
            $table->string('publish_time')->default('09:00');
            $table->text('caption')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('social_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('format');
            $table->json('options');
            $table->json('items');
            $table->text('caption')->nullable();
            $table->timestamps();
        });
        Schema::create('social_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('social_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_connection_id')->constrained()->restrictOnDelete();
            $table->string('platform');
            $table->unsignedInteger('part');
            $table->string('dedupe_key')->unique();
            $table->string('status')->default('queued');
            $table->json('remote_ids')->nullable();
            $table->string('external_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_publications');
        Schema::dropIfExists('social_batches');
        Schema::dropIfExists('social_connections');
    }
};
