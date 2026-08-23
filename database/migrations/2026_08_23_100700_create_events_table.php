<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('organizer_name')->nullable();
            $table->string('organizer_url')->nullable();

            $table->string('title');
            $table->string('slug');
            $table->string('subtitle')->nullable();
            $table->longText('description')->nullable();
            $table->string('short_description', 500)->nullable();

            $table->string('poster')->nullable();
            $table->json('gallery')->nullable();

            $table->enum('price_type', ['free', 'donation', 'ticket', 'membership', 'unknown'])->default('unknown');
            $table->decimal('price_min', 8, 2)->nullable();
            $table->decimal('price_max', 8, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('price_notes')->nullable();

            $table->string('ticket_url')->nullable();
            $table->boolean('booking_required')->default(false);
            $table->string('booking_url')->nullable();
            $table->string('booking_phone', 40)->nullable();

            $table->string('age_restriction', 40)->nullable();
            $table->string('language', 5)->nullable();
            $table->boolean('is_outdoor')->default(false);
            $table->json('custom_location')->nullable();
            $table->json('external_links')->nullable();

            $table->enum('source', ['manual', 'venue', 'submission', 'import_ics', 'import_api'])->default('manual');
            $table->string('source_ref')->nullable();
            $table->enum('verification_status', ['unverified', 'venue_confirmed', 'editorial_checked'])->default('unverified');
            $table->enum('status', ['draft', 'pending', 'published', 'rejected', 'cancelled', 'archived'])->default('draft');
            $table->text('rejection_reason')->nullable();

            $table->boolean('is_featured')->default(false);
            $table->dateTime('featured_until')->nullable();
            $table->integer('editorial_score')->default(0);
            $table->dateTime('published_at')->nullable();
            $table->json('seo')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('saves_count')->default(0);

            $table->datetimes();
            $table->softDeletesDatetime();

            $table->unique(['city_id', 'slug']);
            $table->index(['city_id', 'status']);
            $table->index(['venue_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index(['source', 'source_ref']);
            $table->index(['is_featured', 'featured_until']);
            $table->index('editorial_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
