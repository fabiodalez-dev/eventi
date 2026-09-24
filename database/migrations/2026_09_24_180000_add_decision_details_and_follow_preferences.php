<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_occurrences', function (Blueprint $table): void {
            $table->json('practical_details')->nullable();
            $table->json('cost_breakdown')->nullable();
        });
        Schema::table('follows', fn (Blueprint $table) => $table->string('notification_mode', 20)->default('all'));
        Schema::create('occurrence_checkin_staff', function (Blueprint $table): void {
            $table->foreignId('occurrence_id')->constrained('event_occurrences')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['occurrence_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occurrence_checkin_staff');
        Schema::table('follows', fn (Blueprint $table) => $table->dropColumn('notification_mode'));
        Schema::table('event_occurrences', fn (Blueprint $table) => $table->dropColumn(['practical_details', 'cost_breakdown']));
    }
};
