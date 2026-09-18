<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('whatsapp_phone')->nullable();
            $table->string('whatsapp_phone_hash', 64)->nullable()->unique();
            $table->timestamp('whatsapp_verified_at')->nullable();
            $table->timestamp('whatsapp_prompted_at')->nullable();
            $table->timestamp('community_suspended_at')->nullable();
        });
        // The owner confirmed that all accounts predating this release are test accounts.
        DB::table('users')->whereNull('email_verified_at')->whereNull('deleted_at')->update(['email_verified_at' => now()]);
        Schema::create('whatsapp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('phone');
            $table->string('phone_hash', 64)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('community_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('handle', 40)->unique();
            $table->string('display_name', 80);
            $table->string('bio', 500)->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visibility', 16)->default('members');
            $table->boolean('indexable')->default(false);
            $table->boolean('featured')->default(false)->index();
            $table->timestamps();
        });
        Schema::create('followables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('followable');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'followable_type', 'followable_id'], 'community_follow_unique');
        });
        Schema::create('user_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'blocked_user_id']);
        });
        Schema::table('saved_events', function (Blueprint $table): void {
            $table->string('visibility', 16)->default('private');
        });
        Schema::create('community_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'occurrence_id']);
        });
        Schema::create('community_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->string('body', 500)->nullable();
            $table->string('intent', 16)->default('recommend');
            $table->string('status', 16)->default('published');
            $table->timestamp('published_at');
            $table->timestamps();
            $table->index(['user_id', 'published_at', 'id']);
            $table->index(['status', 'published_at', 'id']);
        });
        Schema::create('community_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('community_comments')->cascadeOnDelete();
            $table->string('body', 1000);
            $table->string('status', 16)->default('published');
            $table->timestamps();
            $table->index(['community_post_id', 'status', 'id']);
        });
        Schema::create('community_profile_venue', function (Blueprint $table): void {
            $table->foreignId('community_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->primary(['community_profile_id', 'venue_id']);
        });
    }

    public function down(): void
    {
        foreach (['community_profile_venue', 'community_comments', 'community_posts', 'community_restrictions', 'user_blocks', 'followables', 'community_profiles', 'whatsapp_challenges'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('saved_events', fn (Blueprint $table) => $table->dropColumn('visibility'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['whatsapp_phone', 'whatsapp_phone_hash', 'whatsapp_verified_at', 'whatsapp_prompted_at', 'community_suspended_at']));
    }
};
