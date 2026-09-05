<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->json('booking_fields')->nullable();
        });
        Schema::table('bookings', function (Blueprint $table): void {
            $table->text('booker_data')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('privacy_version', 40)->nullable();
        });
        Schema::table('admission_tickets', function (Blueprint $table): void {
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admission_tickets', fn (Blueprint $table) => $table->dropColumn(['first_name', 'last_name']));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn(['booker_data', 'privacy_accepted_at', 'privacy_version']));
        Schema::table('event_occurrences', fn (Blueprint $table) => $table->dropColumn('booking_fields'));
    }
};
