<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsorship_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode')->default('selected');
            $table->string('placement')->default('list_top');
            $table->boolean('enabled')->default(true);
            $table->boolean('complimentary')->default(false);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('amount_cents')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'starts_at', 'ends_at']);
        });
        Schema::table('sponsorships', function (Blueprint $table): void {
            $table->foreignId('sponsorship_grant_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['sponsorship_grant_id', 'event_id', 'placement'], 'sponsorship_grant_event_placement_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sponsorships', function (Blueprint $table): void {
            $table->dropForeign(['sponsorship_grant_id']);
        });
        Schema::table('sponsorships', function (Blueprint $table): void {
            $table->dropUnique('sponsorship_grant_event_placement_unique');
            $table->dropColumn('sponsorship_grant_id');
        });
        Schema::dropIfExists('sponsorship_grants');
    }
};
