<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            $table->boolean('ticketing_enabled')->default(false);
        });
        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->boolean('booking_enabled')->default(false);
            $table->unsignedInteger('booking_capacity')->nullable();
            $table->unsignedTinyInteger('booking_limit')->default(6);
            $table->boolean('booking_waitlist')->default(false);
            $table->timestamp('booking_opens_at')->nullable();
            $table->timestamp('booking_closes_at')->nullable();
            $table->timestamp('cancellation_closes_at')->nullable();
            $table->text('booking_instructions')->nullable();
        });
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->string('status', 24);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['occurrence_id', 'status', 'id']);
        });
        Schema::create('admission_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64)->unique();
            $table->string('attendee_name', 120);
            $table->string('status', 24);
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_tickets');
        Schema::dropIfExists('bookings');
        Schema::table('event_occurrences', fn (Blueprint $table) => $table->dropColumn(['booking_enabled', 'booking_capacity', 'booking_limit', 'booking_waitlist', 'booking_opens_at', 'booking_closes_at', 'cancellation_closes_at', 'booking_instructions']));
        Schema::table('venues', fn (Blueprint $table) => $table->dropColumn('ticketing_enabled'));
    }
};
