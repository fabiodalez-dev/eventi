<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('carpool_suspended_at')->nullable()->index();
            $table->timestamp('social_suspended_at')->nullable()->index();
        });
        Schema::create('carpool_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('adult_declared_at')->nullable();
            $table->string('adult_version', 40)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 40)->nullable();
            $table->string('terms_hash', 64)->nullable();
            $table->timestamp('driver_declared_at')->nullable();
            $table->boolean('push_enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('ride_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->restrictOnDelete();
            $table->string('leg', 16);
            $table->string('status', 20);
            $table->string('active_key', 100)->nullable()->unique();
            $table->string('zone', 120);
            $table->timestamp('departure_at');
            $table->unsignedTinyInteger('capacity');
            $table->string('accessibility', 32)->default('not_specified');
            $table->string('accessibility_note', 300)->nullable();
            $table->string('note', 500)->nullable();
            $table->json('stops')->nullable();
            $table->json('snapshot');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 60)->nullable();
            $table->timestamps();
            $table->index(['occurrence_id', 'leg', 'status', 'departure_at'], 'ride_discovery');
        });
        Schema::create('ride_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ride_offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('seats');
            $table->boolean('companions_adult')->default(false);
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('stop_index')->nullable();
            $table->unsignedInteger('offer_revision');
            $table->string('status', 20);
            $table->string('active_key', 100)->nullable()->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 60)->nullable();
            $table->timestamps();
            $table->index(['ride_offer_id', 'status']);
            $table->index(['user_id', 'status']);
        });
        Schema::create('ride_occupancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->restrictOnDelete();
            $table->string('leg', 16);
            $table->foreignId('ride_offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('ride_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['user_id', 'occurrence_id', 'leg'], 'ride_single_occupancy');
        });
        Schema::create('ride_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ride_request_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('conversation_id')->unique();
            $table->timestamp('read_only_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });
        Schema::create('ride_chat_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ride_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('muted')->default(false);
            $table->boolean('archived')->default(false);
            $table->unsignedBigInteger('read_through_id')->default(0);
            $table->unique(['ride_conversation_id', 'user_id']);
        });
        Schema::create('carpool_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('action', 50);
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('result_id');
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
        });
        Schema::create('carpool_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('context')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('context_expires_at')->index();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
        Schema::create('ride_searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->restrictOnDelete();
            $table->string('leg', 16);
            $table->string('zone', 120)->nullable();
            $table->unsignedTinyInteger('seats');
            $table->timestamp('earliest_at');
            $table->timestamp('latest_at');
            $table->string('accessibility', 32)->default('not_specified');
            $table->boolean('is_public')->default(false);
            $table->boolean('alerts_enabled')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'occurrence_id', 'leg']);
        });
        Schema::create('ride_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ride_search_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ride_offer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['ride_search_id', 'ride_offer_id']);
        });
        Schema::create('ride_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('settings');
            $table->timestamps();
        });
        Schema::create('ride_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ride_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->text('body')->nullable();
            $table->timestamps();
            $table->unique(['ride_request_id', 'user_id']);
        });
        Schema::create('carpool_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ride_offer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('ride_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('social_type', 30)->nullable();
            $table->unsignedBigInteger('social_id')->nullable();
            $table->string('reason', 40);
            $table->text('body');
            $table->string('status', 20)->default('open');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('hold_until')->nullable()->index();
            $table->string('hold_reason', 500)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('carpool_case_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('carpool_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('community_delivery_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('notification_id')->unique();
            $table->string('dedupe_key', 190)->unique();
            $table->json('payload');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('available_at')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['community_delivery_outbox', 'carpool_case_messages', 'carpool_cases', 'ride_feedback', 'ride_templates', 'ride_suggestions', 'ride_searches', 'carpool_audits', 'carpool_commands', 'ride_chat_preferences', 'ride_conversations', 'ride_occupancies', 'ride_requests', 'ride_offers', 'carpool_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['carpool_suspended_at', 'social_suspended_at']));
    }
};
