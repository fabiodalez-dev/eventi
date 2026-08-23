<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', [
                'bar', 'pub', 'circolo', 'centro_sociale', 'club', 'teatro', 'cinema',
                'libreria', 'associazione', 'galleria', 'spazio_pubblico', 'ristorante', 'altro',
            ])->default('altro');
            $table->text('description')->nullable();
            $table->string('short_description', 500)->nullable();

            $table->string('address');
            $table->string('address_extra')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('municipality');
            $table->string('province_code', 4);

            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->geometry('location', 'point', 0);

            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->json('socials')->nullable();
            $table->json('opening_hours')->nullable();

            $table->unsignedInteger('capacity')->nullable();
            $table->json('accessibility')->nullable();
            $table->boolean('requires_membership')->default(false);
            $table->text('membership_notes')->nullable();

            $table->enum('status', ['draft', 'pending', 'approved', 'suspended', 'rejected'])->default('draft');
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_nonprofit')->default(false);
            $table->enum('plan', ['free', 'premium'])->default('free');
            $table->boolean('auto_publish')->default(false);

            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->string('claim_token', 64)->nullable()->unique();

            $table->json('default_event_settings')->nullable();
            $table->json('stats_cache')->nullable();

            $table->datetimes();
            $table->softDeletesDatetime();

            $table->index(['city_id', 'status']);
            $table->index('municipality');
            $table->spatialIndex('location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
