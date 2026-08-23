<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->boolean('is_approved')->default(false);
            $table->unsignedInteger('usage_count')->default(0);
            $table->json('synonyms')->nullable();
            $table->datetimes();

            $table->index(['is_approved', 'usage_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
