<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->enum('type', ['ics', 'json', 'rss', 'api', 'manual'])->default('ics');
            $table->string('url', 1000)->nullable();
            $table->text('credentials')->nullable();
            $table->json('mapping')->nullable();
            $table->foreignId('default_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_run_at')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->text('last_error')->nullable();
            $table->datetimes();

            $table->index(['is_active', 'last_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_sources');
    }
};
