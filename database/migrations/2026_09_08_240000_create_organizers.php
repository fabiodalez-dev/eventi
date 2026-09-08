<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('website', 2048)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('organizer_user', function (Blueprint $table): void {
            $table->foreignId('organizer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['organizer_id', 'user_id']);
        });
        Schema::table('events', fn (Blueprint $table) => $table->foreignId('organizer_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('event_occurrences', fn (Blueprint $table) => $table->foreignId('venue_id')->nullable()->constrained()->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('event_occurrences', fn (Blueprint $table) => $table->dropConstrainedForeignId('venue_id'));
        Schema::table('events', fn (Blueprint $table) => $table->dropConstrainedForeignId('organizer_id'));
        Schema::dropIfExists('organizer_user');
        Schema::dropIfExists('organizers');
    }
};
