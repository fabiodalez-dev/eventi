<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('province_code', 4);
            $table->string('province_name');
            $table->string('region');
            $table->string('country_code', 2)->default('IT');
            $table->string('timezone', 64)->default('Europe/Rome');
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->unsignedTinyInteger('default_zoom')->default(12);
            $table->json('bounds')->nullable();
            $table->unsignedSmallInteger('radius_km')->default(30);
            $table->string('locale', 5)->default('it');
            $table->boolean('is_active')->default(false);
            $table->dateTime('launched_at')->nullable();
            $table->time('night_cutoff_time')->default('06:00:00');
            $table->unsignedSmallInteger('starting_soon_minutes')->default(180);
            $table->json('settings')->nullable();
            $table->datetimes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
